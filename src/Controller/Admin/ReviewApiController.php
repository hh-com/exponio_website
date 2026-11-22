<?php

namespace App\Controller\Admin;

use App\Duplicate\ComparisonMatch;
use App\Duplicate\DuplicateMerger;
use App\Duplicate\DuplicateOutcome;
use App\Entity\Category;
use App\Entity\Item;
use App\Entity\ItemImage;
use App\Entity\ItemUpload;
use App\Entity\Language;
use App\Entity\Topic;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Import\FolderImporter;
use App\Import\ItemRemover;
use App\Media\CropBox;
use App\Media\ImageCropper;
use App\Media\ImageRotator;
use App\Recognition\RecognitionApplier;
use App\Recognition\RecognitionService;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\ItemRepository;
use App\Repository\ItemUploadRepository;
use App\Repository\TopicRepository;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * JSON-API für die Prüfseite (assets/admin/ReviewApp.vue): KI-Vorschläge prüfen und freigeben,
 * mögliche Duplikate zuordnen oder als eigenes Objekt bestätigen, vorgeschlagene bessere Fotos übernehmen.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/api', name: 'admin_api_')]
final class ReviewApiController extends AbstractController
{
    public const CSRF_ID = 'admin-api';

    public function __construct(
        private readonly ItemRepository $items,
        private readonly ItemUploadRepository $uploads,
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly EntityManagerInterface $em,
        private readonly AppExtension $app,
        private readonly Packages $packages,
        private readonly ItemRemover $remover,
    ) {
    }

    #[Route('/review', name: 'review_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $proposals = array_flip($this->uploads->findItemIdsWithProposals());
        $queue = array_map(fn (Item $item): array => [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'status' => $item->getStatus()->value,
            'thumb' => $this->thumb($item),
            'confidence' => $item->getAiConfidence(),
            'recognized' => null !== $item->getRecognizedAt(),
            'error' => null !== $item->getAiError(),
            'folder' => $item->getSourceFolder(),
            'createdAt' => $item->getCreatedAt()->format(\DATE_ATOM),
            'duplicateOf' => $item->isPossibleDuplicate() ? $item->getDuplicateOf()?->getId() : null,
            'proposal' => isset($proposals[(int) $item->getId()]),
        ], $this->items->findReviewQueue());

        return $this->json(['items' => $queue, 'meta' => $this->meta()]);
    }

    #[Route('/review/{id}', name: 'review_item', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json(['item' => $this->itemData($this->find($id))]);
    }

    #[Route('/review/{id}', name: 'review_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request, ValidatorInterface $validator, RecognitionApplier $applier): JsonResponse
    {
        $this->checkCsrf($request);
        $item = $this->find($id);
        $data = $this->payload($request);

        if (\array_key_exists('areaId', $data)) {
            $item->setArea(null !== $data['areaId'] ? $this->areas->find((int) $data['areaId']) : null);
        }
        if (\array_key_exists('categoryId', $data)) {
            $item->setCategory(null !== $data['categoryId'] ? $this->categories->find((int) $data['categoryId']) : null);
        }
        if (\is_array($data['topicIds'] ?? null)) {
            foreach ($item->getTopics()->toArray() as $topic) {
                $item->removeTopic($topic);
            }
            foreach ($this->topics->findBy(['id' => array_map('intval', $data['topicIds'])]) as $topic) {
                $item->addTopic($topic);
            }
        }
        foreach (['title', 'description', 'series', 'defects', 'metaDescription', 'internalNotes', 'storageLocation', 'aiHint'] as $field) {
            if (\array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $item->{'set'.ucfirst($field)}('' === $value ? null : $value);
            }
        }
        foreach (['year', 'yearTo'] as $field) {
            if (\array_key_exists($field, $data)) {
                $item->{'set'.ucfirst($field)}(is_numeric($data[$field]) ? (int) $data[$field] : null);
            }
        }
        if (\array_key_exists('issueDate', $data)) {
            $date = \is_string($data['issueDate']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data['issueDate']) : false;
            $item->setIssueDate(false === $date ? null : $date);
        }
        if (\array_key_exists('catalogValue', $data)) {
            $value = str_replace(',', '.', trim((string) $data['catalogValue']));
            $item->setCatalogValue(is_numeric($value) ? (float) $value : null);
        }
        foreach (['featured', 'showEstimate'] as $field) {
            if (\array_key_exists($field, $data)) {
                $item->{'set'.ucfirst($field)}((bool) $data[$field]);
            }
        }
        foreach (\is_array($data['images'] ?? null) ? $data['images'] : [] as $info) {
            foreach ($item->getImages() as $image) {
                if (\is_array($info) && $image->getId() === ($info['id'] ?? null)) {
                    $image->setSide(ImageSide::tryFrom((string) ($info['side'] ?? '')));
                    $image->setAlt(\is_string($info['alt'] ?? null) ? $info['alt'] : null);
                }
            }
        }
        if (\is_array($data['attributes'] ?? null) && null !== $item->getArea()) {
            foreach ($item->getArea()->getAttributeDefinitions() as $definition) {
                $raw = $data['attributes'][(string) $definition->getCode()] ?? null;
                $item->setAttributeValue($definition, null === $raw ? null : $applier->normalizeValue($definition, (string) $raw));
            }
        }
        $item->pruneForeignAttributes();

        match ($data['action'] ?? 'save') {
            'publish' => $item->setStatus(ItemStatus::Published),
            'archive' => $item->setStatus(ItemStatus::Archived),
            'review' => $item->setStatus(ItemStatus::Review),
            default => null,
        };

        $violations = $validator->validate($item);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            return $this->json(['errors' => $errors], 422);
        }
        $this->em->flush();

        return $this->json(['item' => $this->itemData($item)]);
    }

    /** Objekt endgültig löschen – mit allen Fotos und seinen Upload-Ordnern (App\Import\ItemRemover). */
    #[Route('/review/{id}/delete', name: 'review_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $result = $this->remover->remove($this->find($id));

        return $this->json(['deleted' => $id, ...$result]);
    }

    #[Route('/review/{id}/recognize', name: 'review_recognize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recognize(int $id, Request $request, RecognitionService $recognition): JsonResponse
    {
        $this->checkCsrf($request);
        $this->releaseSession($request);
        $item = $this->find($id);
        // der Hinweis an die KI aus dem Formular gilt gleich für diese Erkennung (und bleibt für spätere gespeichert)
        $data = '' === $request->getContent() ? [] : $this->payload($request);
        if (\array_key_exists('aiHint', $data)) {
            $item->setAiHint(\is_string($data['aiHint']) ? $data['aiHint'] : null);
            $this->em->flush();
        }
        set_time_limit(600);
        $ok = $recognition->recognize($item);
        $duplicate = $recognition->lastDuplicate();
        if (DuplicateOutcome::MERGED === $duplicate->result && null !== $duplicate->upload) {
            return $this->json(['ok' => true, 'error' => null, 'merged' => $this->mergedData($duplicate->upload), 'meta' => $this->meta()]);
        }

        return $this->json(['ok' => $ok, 'error' => $ok ? null : $item->getAiError(), 'item' => $this->itemData($item), 'meta' => $this->meta()]);
    }

    /**
     * Mögliches Duplikat entscheiden: dem vorhandenen Objekt zuordnen (Fotos behalten oder ersetzen)
     * oder als eigenes Objekt bestätigen.
     */
    #[Route('/review/{id}/duplicate', name: 'review_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(int $id, Request $request, DuplicateMerger $merger): JsonResponse
    {
        $this->checkCsrf($request);
        $item = $this->find($id);
        $action = (string) ($this->payload($request)['action'] ?? '');
        if ('separate' === $action) {
            $merger->separate($item);

            return $this->json(['item' => $this->itemData($item)]);
        }
        $target = $item->getDuplicateOf();
        if (!\in_array($action, ['keep', 'replace'], true) || null === $target || !$item->isPossibleDuplicate()) {
            return $this->json(['error' => 'Kein Duplikat-Hinweis vorhanden oder unbekannte Aktion.'], 422);
        }
        $upload = $merger->merge($item, $target, ComparisonMatch::fromArray($item->getDuplicateResult() ?? []), 'replace' === $action, true);

        return $this->json(['merged' => $this->mergedData($upload)]);
    }

    /** Vorgeschlagene bessere Fotos übernehmen oder ablehnen (veröffentlichte Objekte). */
    #[Route('/review/{id}/proposal/{uploadId}', name: 'review_proposal', requirements: ['id' => '\d+', 'uploadId' => '\d+'], methods: ['POST'])]
    public function proposal(int $id, int $uploadId, Request $request, DuplicateMerger $merger): JsonResponse
    {
        $this->checkCsrf($request);
        $item = $this->find($id);
        $upload = null;
        foreach ($item->getUploads() as $candidate) {
            if ($candidate->getId() === $uploadId && $candidate->isProposal()) {
                $upload = $candidate;
            }
        }
        if (null === $upload) {
            throw new NotFoundHttpException('Kein offener Vorschlag.');
        }
        $action = (string) ($this->payload($request)['action'] ?? '');
        if ('accept' === $action) {
            $merger->useImages($upload);
        } elseif ('reject' === $action) {
            $merger->rejectProposal($upload);
        } else {
            throw new BadRequestHttpException('Unbekannte Aktion.');
        }

        return $this->json(['item' => $this->itemData($item)]);
    }

    /**
     * Bilder sortieren, löschen, drehen und zuschneiden:
     * {order: [id, …], delete: [id, …], rotate: {id: Grad im Uhrzeigersinn}, crop: {id: {x, y, w, h} oder null}}.
     * Der Ausschnitt bezieht sich auf das angezeigte Bild (Anteile 0–1), null hebt den Zuschnitt auf.
     * Jeder Schlüssel ist optional; Drehen und Zuschneiden erzeugen neue Bilddateien (App\Media\ImageRotator, ImageCropper).
     */
    #[Route('/review/{id}/images', name: 'review_images', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function images(int $id, Request $request, ImageRotator $rotator, ImageCropper $cropper): JsonResponse
    {
        $this->checkCsrf($request);
        $item = $this->find($id);
        $data = $this->payload($request);
        $order = array_map('intval', \is_array($data['order'] ?? null) ? $data['order'] : []);
        $delete = array_map('intval', \is_array($data['delete'] ?? null) ? $data['delete'] : []);
        $rotate = \is_array($data['rotate'] ?? null) ? $data['rotate'] : [];
        $crop = \is_array($data['crop'] ?? null) ? $data['crop'] : [];
        $error = null;

        foreach ($item->getImages()->toArray() as $image) {
            if (\in_array($image->getId(), $delete, true)) {
                $item->removeImage($image);
                continue;
            }
            if (\array_key_exists((int) $image->getId(), $crop)) {
                $box = $crop[(int) $image->getId()];
                try {
                    if (null === $box) {
                        $cropper->reset($image);
                    } elseif (\is_array($box)) {
                        $cropper->crop($image, CropBox::fromArray($box));
                    }
                } catch (\RuntimeException|\InvalidArgumentException $e) {
                    $error = 'Bild konnte nicht zugeschnitten werden: '.$e->getMessage();
                }
            }
            $degrees = $rotate[(int) $image->getId()] ?? null;
            if (is_numeric($degrees)) {
                try {
                    $rotator->rotate($image, (int) $degrees);
                } catch (\RuntimeException $e) {
                    $error = 'Bild konnte nicht gedreht werden: '.$e->getMessage();
                }
            }
            $position = array_search($image->getId(), $order, true);
            if (false !== $position) {
                $image->setPosition((int) $position);
            }
        }
        // auch bei einem Fehler speichern: bereits gedrehte oder zugeschnittene Bilder haben neue Dateien, die alten sind gelöscht
        $this->em->flush();
        if (null !== $error) {
            return $this->json(['error' => $error], 422);
        }
        $this->em->refresh($item);

        return $this->json(['item' => $this->itemData($item)]);
    }

    #[Route('/scan', name: 'scan', methods: ['POST'])]
    public function scan(Request $request, FolderImporter $importer): JsonResponse
    {
        $this->checkCsrf($request);
        $this->releaseSession($request);
        set_time_limit(600);
        try {
            $result = $importer->importNew(50);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'imported' => \count($result['imported']),
            'failed' => $result['failed'],
        ]);
    }

    #[Route('/categories', name: 'category_create', methods: ['POST'])]
    public function createCategory(Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $data = $this->payload($request);
        $nameDe = trim((string) ($data['nameDe'] ?? ''));
        if ('' === $nameDe) {
            return $this->json(['error' => 'Name fehlt.'], 422);
        }
        $parent = isset($data['parentId']) ? $this->categories->find((int) $data['parentId']) : null;
        $category = new Category(mb_substr($nameDe, 0, 150), $parent);
        $this->em->persist($category);
        $this->em->flush();

        return $this->json(['id' => $category->getId(), 'label' => $category->getPathLabel()]);
    }

    #[Route('/topics', name: 'topic_create', methods: ['POST'])]
    public function createTopic(Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $data = $this->payload($request);
        $nameDe = trim((string) ($data['nameDe'] ?? ''));
        if ('' === $nameDe) {
            return $this->json(['error' => 'Name fehlt.'], 422);
        }
        $topic = $this->topics->findOneByName($nameDe) ?? new Topic(mb_substr($nameDe, 0, 120));
        $this->em->persist($topic);
        $this->em->flush();

        return $this->json(['id' => $topic->getId(), 'name' => $topic->getName()]);
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        $categories = array_map(static fn (Category $c): array => ['id' => $c->getId(), 'label' => $c->getPathLabel()], $this->categories->findAllOrdered());
        usort($categories, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        $areas = [];
        foreach ($this->areas->findAllWithDefinitions() as $area) {
            $definitions = [];
            foreach ($area->getAttributeDefinitions() as $d) {
                $definitions[] = [
                    'code' => $d->getCode(),
                    'label' => $d->getLabel(),
                    'type' => $d->getType()->value,
                    'unit' => $d->getUnit(),
                    'options' => $d->getOptions(),
                ];
            }
            $areas[] = ['id' => $area->getId(), 'name' => $area->getName(), 'icon' => $area->getIcon(), 'definitions' => $definitions];
        }

        return [
            'areas' => $areas,
            'categories' => $categories,
            'topics' => array_map(static fn (Topic $t): array => ['id' => $t->getId(), 'name' => $t->getName()], $this->topics->findAllOrdered()),
            'sides' => array_map(static fn (ImageSide $side): array => ['value' => $side->value, 'label' => $side->label()], ImageSide::cases()),
        ];
    }

    /** @return array<string, mixed> */
    private function itemData(Item $item): array
    {
        $attributes = [];
        foreach ($item->getArea()?->getAttributeDefinitions() ?? [] as $definition) {
            $attributes[(string) $definition->getCode()] = $item->getAttributeValue((string) $definition->getCode());
        }
        $ai = $item->getAiResult() ?? [];

        return [
            'id' => $item->getId(),
            'status' => $item->getStatus()->value,
            'areaId' => $item->getArea()?->getId(),
            'categoryId' => $item->getCategory()?->getId(),
            'topicIds' => $item->getTopics()->map(static fn (Topic $t): ?int => $t->getId())->getValues(),
            'title' => $item->getTitle(),
            'description' => $item->getDescription(),
            'defects' => $item->getDefects(),
            'metaDescription' => $item->getMetaDescription(),
            'year' => $item->getYear(),
            'yearTo' => $item->getYearTo(),
            'issueDate' => $item->getIssueDate()?->format('Y-m-d'),
            'series' => $item->getSeries(),
            'attributes' => (object) $attributes,
            'catalogValue' => $item->getCatalogValue(),
            'showEstimate' => $item->isShowEstimate(),
            'featured' => $item->isFeatured(),
            'internalNotes' => $item->getInternalNotes(),
            'storageLocation' => $item->getStorageLocation(),
            'aiHint' => $item->getAiHint(),
            'sourceFolder' => $item->getSourceFolder(),
            'folders' => $this->remover->folders($item),
            'createdAt' => $item->getCreatedAt()->format(\DATE_ATOM),
            'uploadCount' => $item->getUploadCount(),
            'images' => array_map($this->imageData(...), $item->getImages()->getValues()),
            'duplicate' => $this->duplicateData($item),
            'proposals' => array_map($this->proposalData(...), array_values(array_filter($item->getUploads()->getValues(), static fn (ItemUpload $u): bool => $u->isProposal()))),
            'ai' => [
                'confidence' => $item->getAiConfidence(),
                'model' => $item->getAiModel(),
                'recognizedAt' => $item->getRecognizedAt()?->format(\DATE_ATOM),
                'error' => $item->getAiError(),
                'notes' => \is_string($ai['notes'] ?? null) ? $ai['notes'] : null,
                'alternatives' => \is_array($ai['alternatives'] ?? null) ? array_values(array_filter($ai['alternatives'], 'is_string')) : [],
                'isCollectible' => $ai['is_collectible'] ?? null,
            ],
            'urls' => [
                'edit' => $this->generateUrl('admin_item_edit', ['entityId' => $item->getId()]),
                'public' => $this->app->itemUrl($item, false, Language::SOURCE),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function imageData(ItemImage $image): array
    {
        return [
            'id' => $image->getId(),
            'thumb' => $this->packages->getUrl($image->getRelativePath(400)),
            'medium' => $this->packages->getUrl($image->getRelativePath(800)),
            'full' => $this->packages->getUrl($image->getRelativePath()),
            'width' => $image->getWidth(),
            'height' => $image->getHeight(),
            'side' => $image->getSide()?->value,
            'alt' => $image->getAlt(),
            // Zuschnitt gegenüber dem Original, lässt sich aufheben
            'cropped' => null !== $image->getCrop(),
        ];
    }

    /** @return array<string, mixed>|null Hinweis „mögliches Duplikat von #ID“ mit den Fotos des vorhandenen Objekts */
    private function duplicateData(Item $item): ?array
    {
        $of = $item->getDuplicateOf();
        if (null === $of || !$item->isPossibleDuplicate()) {
            return null;
        }
        $match = ComparisonMatch::fromArray($item->getDuplicateResult() ?? []);

        return [
            'id' => $of->getId(),
            'title' => $of->getTitle(),
            'status' => $of->getStatus()->value,
            'uploadCount' => $of->getUploadCount(),
            'confidence' => $item->getDuplicateConfidence(),
            'reason' => $item->getDuplicateReason(),
            'specimen' => $match?->specimen->label(),
            'condition' => $match?->conditionLabel(),
            'photos' => $match?->photosLabel(),
            'better' => $match?->isBetter(),
            'images' => array_map($this->imageData(...), $of->getImages()->getValues()),
            'urls' => [
                'edit' => $this->generateUrl('admin_item_edit', ['entityId' => $of->getId()]),
                'detail' => $this->generateUrl('admin_item_detail', ['entityId' => $of->getId()]),
            ],
        ];
    }

    /** @return array<string, mixed> vorgeschlagene bessere Fotos eines späteren Uploads */
    private function proposalData(ItemUpload $upload): array
    {
        return [
            'id' => $upload->getId(),
            'folder' => $upload->getFolder(),
            'uploadedAt' => $upload->getUploadedAt()->format(\DATE_ATOM),
            'confidence' => $upload->getConfidence(),
            'reason' => $upload->getReason(),
            'specimen' => $upload->getSpecimen()?->label(),
            'images' => array_map($this->imageData(...), $upload->getImages()->getValues()),
        ];
    }

    /** @return array<string, mixed> Antwort nach dem Zuordnen: wohin der Upload gewandert ist */
    private function mergedData(ItemUpload $upload): array
    {
        $target = $upload->getItem();

        return [
            'id' => $target->getId(),
            'title' => $target->getTitle(),
            'uploadCount' => $target->getUploadCount(),
            'summary' => DuplicateOutcome::uploadSummary($upload),
            'urls' => [
                'edit' => $this->generateUrl('admin_item_edit', ['entityId' => $target->getId()]),
                'detail' => $this->generateUrl('admin_item_detail', ['entityId' => $target->getId()]),
            ],
        ];
    }

    private function thumb(Item $item): ?string
    {
        $image = $item->getMainImage();

        return null === $image ? null : $this->packages->getUrl($image->getRelativePath(400));
    }

    private function find(int $id): Item
    {
        $item = $this->items->find($id);
        if (null === $item) {
            throw new NotFoundHttpException();
        }
        $this->items->preload([$item]);

        return $item;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('JSON erwartet.');
        }

        return $data;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            throw new AccessDeniedHttpException('Ungültiges CSRF-Token – Seite neu laden.');
        }
    }

    /**
     * PHP sperrt die Session, solange eine Anfrage läuft. Lange Aktionen (KI, Import) geben sie deshalb
     * nach der CSRF-Prüfung frei – sonst warten alle anderen Backend-Seiten im selben Browser, bis sie fertig sind.
     */
    private function releaseSession(Request $request): void
    {
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }
    }
}
