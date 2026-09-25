/**
 * config.js — فقط داده (Data-only)
 * توابع کمکی در utils.js قرار دارند
 * این فایل توسط admin از پنل مدیریت بازنویسی می‌شود
 */

const PRICING_RULES = {
    FREE_WIRE_CM:      20,   // سانتی‌متر سیم رایگان
    EXTRA_WIRE_PER_CM: 400,  // هزینه هر سانت سیم اضافه (تومان)
    MIN_LENGTH_CM:     50,   // حداقل طول محاسباتی برای قطعات خرد
};

const TIERS = [
    { min: 15,  max: 29,   discount: 3  },
    { min: 30,  max: 59,   discount: 6  },
    { min: 60,  max: 119,  discount: 9  },
    { min: 120, max: 9999, discount: 12 },
];

const PRODUCTS_DATA = {
    L1: {
        title:         'مدل L1',
        price:         1500000,
        image:         '/images/L1.jpeg',
        desc:          'پروفیل نور خطی مناسب نصب توکار کمد و کلوزت، کابینت با طراحی ظریف و پخش نور یکنواخت.',
        allowedColors: ['مشکی', 'سفید'],
        allowedLights: ['آفتابی (3000K)'],
    },
    T1: {
        title:         'مدل T1',
        price:         1500000,
        image:         '/images/T1.jpeg',
        desc:          'پروفیل نور خطی مناسب نصب توکار کمد و کلوزت، کابینت با طراحی ظریف و پخش نور یکنواخت.',
        allowedColors: ['مشکی', 'سفید'],
        allowedLights: ['آفتابی (3000K)'],
    },
    T2: {
        title:         'مدل T2',
        price:         1600000,
        image:         '/images/T2.jpeg',
        desc:          'پروفیل نور خطی مناسب نصب توکار کمد و کلوزت، کابینت با طراحی ظریف و پخش نور یکنواخت.',
        allowedColors: ['مشکی', 'سفید'],
        allowedLights: ['آفتابی (3000K)'],
    },
    TK3: {
        title:         'مدل کنافی TK3',
        price:         1900000,
        image:         '/images/tk3.png',
        desc:          'پروفیل نور خطی مناسب نصب توکار کنافی و پخش نور یکنواخت. ۳۶ تا ۴۰ وات بر متر',
        allowedColors: ['مشکی', 'سفید'],
        allowedLights: ['آفتابی (3000K)'],
    },
};
