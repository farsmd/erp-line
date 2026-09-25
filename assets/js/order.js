/**
 * order.js — منطق فرم ثبت سفارش
 * وابستگی‌ها (به ترتیب لود): config.js → utils.js → order.js
 */

// ─────────────────────────────────────────
// DOM References
// ─────────────────────────────────────────

const DOM = {
    productsContainer: document.getElementById('productsContainer'),
    addProductBtn:     document.getElementById('addProductBtn'),
    previewBtn:        document.getElementById('previewBtn'),
    recalculateBtn:    document.getElementById('recalculateBtn'),
    previewModal:      document.getElementById('previewModal'),
    closeModalBtn:     document.getElementById('closeModalBtn'),
    cancelSubmitBtn:   document.getElementById('cancelSubmitBtn'),
    submitOrderBtn:    document.getElementById('submitOrderBtn'),
    previewContent:    document.getElementById('previewContent'),
    projectName:       document.getElementById('projectName'),
    customerPhone:     document.getElementById('customerPhone'),
    orderPostForm:     document.getElementById('orderPostForm'),
    orderJsonInput:    document.getElementById('orderJsonInput'),
    // Summary elements
    totalRowsEl:       document.getElementById('totalRows'),
    totalQtyEl:        document.getElementById('totalQty'),
    totalMetersUi:     document.getElementById('totalMetersUi'),
    grandTotalEl:      document.getElementById('grandTotal'),
    discountNote:      document.getElementById('discountNote'),
};

let productIndex = 0;

// ─────────────────────────────────────────
// INIT
// ─────────────────────────────────────────

function init() {
    // نمایش دکمه‌های پنهان
    ['addProductBtn', 'previewBtn', 'recalculateBtn'].forEach(id => {
        const el = DOM[id];
        if (el) el.style.display = 'inline-flex';
    });

    // رویدادها
    DOM.addProductBtn?.addEventListener('click', () => {
        DOM.productsContainer.appendChild(createProductItem());
        refreshProductTitles();
        calculateGrandTotal();
    });

    DOM.recalculateBtn?.addEventListener('click', () => {
        DOM.productsContainer.querySelectorAll('.model')
            .forEach(sel => sel.dispatchEvent(new Event('change')));
        calculateGrandTotal();
    });

    DOM.previewBtn?.addEventListener('click', () => {
        calculateGrandTotal();
        buildPreview();
        DOM.previewModal?.classList.add('show');
    });

    DOM.closeModalBtn?.addEventListener('click',  () => DOM.previewModal?.classList.remove('show'));
    DOM.cancelSubmitBtn?.addEventListener('click', () => DOM.previewModal?.classList.remove('show'));

    DOM.projectName?.addEventListener('input', buildPreview);

    DOM.submitOrderBtn?.addEventListener('click', handleSubmit);

    // اضافه کردن اولین ردیف محصول
    DOM.productsContainer?.appendChild(createProductItem());
    refreshProductTitles();
    calculateGrandTotal();
}

// ─────────────────────────────────────────
// PRODUCT ITEM
// ─────────────────────────────────────────

function createProductItem() {
    productIndex++;

    const item = document.createElement('div');
    item.className   = 'product-item';
    item.dataset.index = productIndex;

    const modelOptions = Object.entries(PRODUCTS_DATA ?? {})
        .map(([key, prod]) => `<option value="${key}">${prod.title}</option>`)
        .join('');

    item.innerHTML = `
        <div class="product-top">
            <div class="product-title">محصول شماره ${productIndex}</div>
            <button type="button" class="btn btn-danger remove-btn">حذف ردیف</button>
        </div>
        <div class="fields">
            <div class="field">
                <label>مدل پروفیل</label>
                <select class="model">${modelOptions}</select>
            </div>
            <div class="field">
                <label>رنگ بدنه</label>
                <select class="color"></select>
            </div>
            <div class="field">
                <label>دمای نور</label>
                <select class="light"></select>
            </div>
            <div class="field">
                <label>تعداد محصول</label>
                <div class="stepper">
                    <button type="button" class="qty-plus">+</button>
                    <input type="number" class="qty" min="1" value="1">
                    <button type="button" class="qty-minus">−</button>
                </div>
            </div>
            <div class="field">
                <label>طول سیم خروجی (سانتی‌متر)</label>
                <div class="stepper">
                    <button type="button" class="wire-plus">+</button>
                    <input type="number" class="wire" min="0" step="5" value="${PRICING_RULES?.FREE_WIRE_CM ?? 20}">
                    <button type="button" class="wire-minus">−</button>
                </div>
                <div class="hint">تا ${PRICING_RULES?.FREE_WIRE_CM ?? 20} سانتی‌متر رایگان است.</div>
            </div>
            <div class="field">
                <label>طول فیزیکی پروفیل (سانتی‌متر)</label>
                <input type="number" class="profile" min="1" step="0.1" placeholder="مثال: 102.5" value="100">
                <div class="hint">
                    قطعات زیر ${PRICING_RULES?.MIN_LENGTH_CM ?? 50} سانت، معادل نیم‌متر محاسبه می‌شوند.
                </div>
            </div>
            <div class="field full">
                <label>توضیحات و محل نصب (اختیاری — چاپ روی لیبل قطعه)</label>
                <input type="text" class="item-desc"
                       placeholder="مثال: زیر کابینت آشپزخانه، کمد اتاق مستر...">
            </div>
        </div>
        <div class="item-price-box">
            <div class="price-badge item-summary">مبلغ پایه ردیف: ۰ تومان</div>
            <div class="hint">شامل هزینه پروفیل + سیم مازاد × تعداد</div>
        </div>
    `;

    // ─── Refs داخل item ───
    const q = (cls) => item.querySelector(cls);
    const modelEl   = q('.model');
    const colorEl   = q('.color');
    const lightEl   = q('.light');
    const qtyEl     = q('.qty');
    const wireEl    = q('.wire');
    const profileEl = q('.profile');
    const descEl    = q('.item-desc');
    const badge     = q('.item-summary');

    // ─── به‌روزرسانی گزینه‌های رنگ و نور بر اساس مدل ───
    function updateOptions() {
        const prod = PRODUCTS_DATA?.[modelEl.value];
        if (!prod) return;
        colorEl.innerHTML = prod.allowedColors.map(c => `<option value="${c}">${c}</option>`).join('');
        lightEl.innerHTML = prod.allowedLights.map(l => `<option value="${l}">${l}</option>`).join('');
    }

    // ─── محاسبه قیمت ردیف ───
    function calcItem() {
        const prod = PRODUCTS_DATA?.[modelEl.value];
        if (!prod) return;

        const qty       = Math.max(1, parseInt(qtyEl.value) || 1);
        const wireCm    = Math.max(0, parseFloat(wireEl.value) || 0);
        const profileCm = Math.max(1, parseFloat(profileEl.value) || 1);

        qtyEl.value  = qty;
        wireEl.value = wireCm;

        const minCm    = PRICING_RULES?.MIN_LENGTH_CM ?? 50;
        const freeCm   = PRICING_RULES?.FREE_WIRE_CM  ?? 20;
        const wireRate = PRICING_RULES?.EXTRA_WIRE_PER_CM ?? 400;

        const billedCm    = Math.max(minCm, profileCm);
        const profileCost = (billedCm / 100) * prod.price;
        const wireCost    = Math.max(0, wireCm - freeCm) * wireRate;
        const rowTotal    = (profileCost + wireCost) * qty;

        badge.textContent = 'مبلغ پایه ردیف: ' + formatPrice(rowTotal);

        // ذخیره در dataset برای collectOrderData و calculateGrandTotal
        Object.assign(item.dataset, {
            model:          modelEl.value,
            modelTitle:     prod.title,
            color:          colorEl.value,
            light:          lightEl.value,
            qty,
            wire:           wireCm,
            profile:        profileCm,
            billedProfileCm: billedCm,
            rowTotal,
            itemDesc:       descEl.value.trim(),
        });

        calculateGrandTotal();
    }

    // ─── Event listeners ───
    modelEl.addEventListener('change', () => { updateOptions(); calcItem(); });
    [colorEl, lightEl, qtyEl, wireEl, profileEl, descEl]
        .forEach(el => el.addEventListener('input', calcItem));

    q('.qty-plus') .addEventListener('click', () => { qtyEl.value  = (parseInt(qtyEl.value)  || 1) + 1;  calcItem(); });
    q('.qty-minus').addEventListener('click', () => { qtyEl.value  = Math.max(1, (parseInt(qtyEl.value) || 1) - 1); calcItem(); });
    q('.wire-plus').addEventListener('click', () => { wireEl.value = (parseInt(wireEl.value) || 0) + 5;  calcItem(); });
    q('.wire-minus').addEventListener('click', () => { wireEl.value = Math.max(0, (parseInt(wireEl.value) || 0) - 5); calcItem(); });

    q('.remove-btn').addEventListener('click', () => {
        item.remove();
        refreshProductTitles();
        calculateGrandTotal();
    });

    updateOptions();
    calcItem();
    return item;
}

// ─────────────────────────────────────────
// GRAND TOTAL
// ─────────────────────────────────────────

function calculateGrandTotal() {
    const items = [...DOM.productsContainer.querySelectorAll('.product-item')];

    let rawTotal  = 0;
    let totalQty  = 0;
    let totalMeters = 0;

    for (const item of items) {
        const qty    = Number(item.dataset.qty   || 0);
        totalQty    += qty;
        rawTotal    += Number(item.dataset.rowTotal || 0);
        totalMeters += (Number(item.dataset.billedProfileCm || 0) * qty) / 100;
    }

    const discountRate   = getTierDiscountRate(totalMeters);
    const discountAmount = rawTotal * discountRate;
    const finalTotal     = rawTotal - discountAmount;

    DOM.totalRowsEl.textContent  = items.length.toLocaleString('fa-IR');
    DOM.totalQtyEl.textContent   = totalQty.toLocaleString('fa-IR');
    DOM.totalMetersUi.textContent = totalMeters.toFixed(2) + ' متر';
    DOM.grandTotalEl.textContent  = formatPrice(finalTotal);

    if (discountRate > 0) {
        DOM.discountNote.textContent = `شامل ${discountRate * 100}٪ تخفیف پلکانی (سود شما: ${formatPrice(discountAmount)})`;
        DOM.discountNote.style.color = 'var(--success)';
    } else {
        DOM.discountNote.textContent = 'بدون تخفیف پلکانی (مختص سفارشات عمده)';
        DOM.discountNote.style.color = 'var(--muted)';
    }
}

// ─────────────────────────────────────────
// PREVIEW (Modal)
// ─────────────────────────────────────────

function buildPreview() {
    const items = [...DOM.productsContainer.querySelectorAll('.product-item')];

    if (!items.length) {
        DOM.previewContent.innerHTML = `<div class="empty-note">هیچ محصولی ثبت نشده است.</div>`;
        return;
    }

    let rawTotal    = 0;
    let totalMeters = 0;
    let html        = `<div class="preview-list">`;

    const projName = DOM.projectName?.value.trim();
    if (projName) {
        html += `<div style="background:#eef2ff;color:#1e3a8a;padding:10px;border-radius:8px;
                             margin-bottom:15px;font-weight:bold;">پروژه: ${projName}</div>`;
    }

    items.forEach((item, idx) => {
        const qty     = Number(item.dataset.qty     || 0);
        const billed  = Number(item.dataset.billedProfileCm || 0);
        const rowTotal = Number(item.dataset.rowTotal || 0);

        rawTotal    += rowTotal;
        totalMeters += (billed * qty) / 100;

        html += `
            <div class="preview-item">
                <h3 style="color:#2563eb;">ردیف ${idx + 1} (${item.dataset.modelTitle || '-'})</h3>
                <div class="preview-grid">
                    <div><strong>رنگ:</strong> ${item.dataset.color || '-'}</div>
                    <div><strong>نور:</strong> ${item.dataset.light || '-'}</div>
                    <div><strong>تعداد:</strong> ${qty}</div>
                    <div><strong>طول فیزیکی:</strong> ${item.dataset.profile || '-'} cm</div>
                    <div style="grid-column:span 2"><strong>توضیحات:</strong> ${item.dataset.itemDesc || 'بدون توضیحات'}</div>
                    <div style="grid-column:span 2;font-weight:bold;color:var(--text)">
                        <strong>مبلغ ردیف:</strong> ${formatPrice(rowTotal)}
                    </div>
                </div>
            </div>`;
    });

    const rate    = getTierDiscountRate(totalMeters);
    const disc    = rawTotal * rate;
    const final_  = rawTotal - disc;

    html += `</div>
        <div class="total-box" style="flex-direction:column;align-items:stretch;">
            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                <span>مبلغ پایه:</span><span>${formatPrice(rawTotal)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:15px;color:#16a34a;font-weight:bold;">
                <span>تخفیف (${rate * 100}٪):</span><span>− ${formatPrice(disc)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid #bfdbfe;padding-top:15px;">
                <span style="font-size:18px;font-weight:bold;">مبلغ قابل پرداخت:</span>
                <span style="font-size:22px;font-weight:900;color:#1d4ed8;">${formatPrice(final_)}</span>
            </div>
        </div>`;

    DOM.previewContent.innerHTML = html;
}

// ─────────────────────────────────────────
// COLLECT & SUBMIT
// ─────────────────────────────────────────

function collectOrderData() {
    const items = [...DOM.productsContainer.querySelectorAll('.product-item')];
    let rawTotal = 0, totalQty = 0, totalMeters = 0;

    const rows = items.map((item, idx) => {
        const qty      = Number(item.dataset.qty || 0);
        const billed   = Number(item.dataset.billedProfileCm || 0);
        const rowTotal = Number(item.dataset.rowTotal || 0);

        rawTotal    += rowTotal;
        totalQty    += qty;
        totalMeters += (billed * qty) / 100;

        return {
            row_number: idx + 1,
            model:      item.dataset.modelTitle || item.dataset.model,
            color:      item.dataset.color  || '',
            light:      item.dataset.light  || '',
            qty,
            wire_cm:    Number(item.dataset.wire    || 0),
            profile_cm: Number(item.dataset.profile || 0),
            item_desc:  item.dataset.itemDesc || '',
            row_total:  rowTotal,
        };
    });

    const rate     = getTierDiscountRate(totalMeters);
    const finalTotal = rawTotal * (1 - rate);

    return {
        project_name:          DOM.projectName?.value.trim() ?? '',
        total_rows:            rows.length,
        total_qty:             totalQty,
        total_meters:          totalMeters,
        discount_rate_percent: rate * 100,
        grand_total_base:      Math.round(rawTotal),
        grand_total_final:     Math.round(finalTotal),
        items:                 rows,
        customer_phone:        DOM.customerPhone?.value.trim() ?? '',
        submitted_at:          new Date().toISOString(),
    };
}

function handleSubmit() {
    const phone = DOM.customerPhone?.value.trim() ?? '';
    if (!phone || phone.length < 10) {
        alert('لطفاً شماره موبایل خود را وارد کنید.');
        DOM.customerPhone?.focus();
        return;
    }

    const payload = collectOrderData();
    if (!payload.items.length) {
        alert('سبد سفارش خالی است.');
        return;
    }

    DOM.orderJsonInput.value = JSON.stringify(payload);
    DOM.orderPostForm.submit();
}

// ─────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────

function refreshProductTitles() {
    DOM.productsContainer.querySelectorAll('.product-item').forEach((item, idx) => {
        item.querySelector('.product-title').textContent = 'محصول شماره ' + (idx + 1);
    });
}

// ─────────────────────────────────────────
// START
// ─────────────────────────────────────────
init();
