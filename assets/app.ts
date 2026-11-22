import './styles/app.css'
import { mountIslands } from './lib/islands'
import { initWatchlist } from './lib/watchlist'
import { initDisclosures } from './lib/disclosure'
import SearchBox from './front/SearchBox.vue'
import MobileMenu from './front/MobileMenu.vue'

initDisclosures()
initWatchlist()

mountIslands({
  // auf jeder Seite im Kopfbereich – direkt mitladen
  'search-box': () => ({ default: SearchBox }),
  'mobile-menu': () => ({ default: MobileMenu }),
  // nur auf einzelnen Seiten – erst bei Bedarf laden
  'catalog-browser': () => import('./front/CatalogBrowser.vue'),
  'item-gallery': () => import('./front/ItemGallery.vue'),
  'inquiry-form': () => import('./front/InquiryForm.vue'),
  watchlist: () => import('./front/WatchlistPage.vue'),
})
