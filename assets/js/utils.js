/**
 * utils.js — توابع کمکی خالص (بدون DOM)
 * این فایل قبل از config.js و order.js لود می‌شود
 */

/**
 * تبدیل عدد به فرمت قیمت فارسی
 * @param {number} num
 * @returns {string}  مثال: ۱٬۵۰۰٬۰۰۰ تومان
 */
function formatPrice(num) {
    return Number(Math.round(num)).toLocaleString('fa-IR') + ' تومان';
}

/**
 * درصد تخفیف پلکانی بر اساس متراژ کل سفارش
 * @param {number} totalMeters
 * @returns {number}  بین ۰ تا ۱
 */
function getTierDiscountRate(totalMeters) {
    if (typeof TIERS === 'undefined') return 0;
    let rate = 0;
    for (const tier of TIERS) {
        if (totalMeters >= tier.min) rate = tier.discount / 100;
    }
    return rate;
}

/**
 * Parse خلاصه User-Agent برای لاگ
 * (در صورت نیاز در فرانت — نسخه کامل در PHP)
 * @param {string} ua
 * @returns {string}
 */
function parseUserAgentBasic(ua = navigator.userAgent) {
    const os =
        /windows/i.test(ua)           ? 'Windows' :
        /mac|iphone/i.test(ua)        ? 'Apple'   :
        /android/i.test(ua)           ? 'Android' :
        /linux/i.test(ua)             ? 'Linux'   : 'ناشناخته';

    const browser =
        /firefox/i.test(ua)           ? 'Firefox' :
        /chrome/i.test(ua)            ? 'Chrome'  :
        /safari/i.test(ua)            ? 'Safari'  : 'ناشناخته';

    return `${os} | ${browser}`;
}
