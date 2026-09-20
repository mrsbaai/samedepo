// Application JavaScript
import './fingerprint';

export const cryptoIcons = import.meta.glob(
    '../../node_modules/cryptocurrency-icons/svg/color/{btc,eth,ltc,bnb,trx,usdt,usdc}.svg',
    { query: '?url' },
);
