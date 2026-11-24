const $ = (selector) => document.querySelector(selector);
const boardEl = $('#board');
const cardDialog = $('#card-dialog');
const rulesDialog = $('#automations-dialog');

let state = null;       // sunucudan gelen son pano
let openCardId = null;
let dragging = false;
let changeCount = 0;    // yarıda kalan polling cevabını ayıklamak için
let queue = Promise.resolve();

// --- Yardımcılar ---

/** DOM elemanı oluşturur. Metinler her zaman text node olarak eklenir (XSS güvenli). */
function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(props)) {
        if (value === undefined || value === null || value === false) continue;
        if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
        else if (key === 'value' || key === 'checked') el[key] = value;
        else el.setAttribute(key, value === true ? '' : value);
    }
    el.append(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));
    return el;
}

let toastTimer;
function toast(message) {
    const el = $('#toast');
    // Açık modalın üstünde görünmesi için en son açılan katmana taşı.
    (document.querySelector('dialog[open]') ?? document.body).append(el);
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 4000);
}

async function request(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': $('meta[name=csrf-token]').content,
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (response.status === 419) {
        // Oturum başka sekmede değişmiş.
        location.reload();
        throw new Error('Oturum yenileniyor…');
    }
    if (response.status === 204) return null;
    const data = await response.json();
    if (!response.ok) throw new Error(response.status === 404 ? 'Kayıt bulunamadı, silinmiş olabilir.' : data.message);
    return data;
}

/** Değişiklikleri sırayla gönderir, sonra panoyu yeniden çizer. */
function mutate(method, url, body, { keepDialog = false } = {}) {
    changeCount++;
    queue = queue.then(async () => {
        try {
            state = await request(method, url, body);
            render(keepDialog);
            if (state.notice) toast(state.notice);
            return true;
        } catch (error) {
            toast(error.message);
            if (state) render(keepDialog);
            return false;
        } finally {
            changeCount++;
        }
    });
    return queue;
}

const user = (id) => state.users.find((u) => u.id === id);
const isAdmin = () => user(state.me).is_admin;
const isLocked = (list) => list.admin_only && !isAdmin();
const showingDone = () => location.hash === '#tamamlananlar';
const findCard = (id) => state.lists.flatMap((l) => l.cards).find((c) => c.id === id);
const items = (card) => card.checklists.flatMap((c) => c.items);

function avatar(u) {
    const initials = u.name.split(' ').map((p) => p[0]).slice(0, 2).join('');
    return h('span', { class: 'avatar', style: `background:${u.color}`, title: u.name }, initials);
}

/** Enter ile gönderilen tek satırlık form. */
function addForm(key, placeholder, buttonText, submit) {
    const input = h('input', { placeholder, 'aria-label': placeholder, 'data-draft': key, maxlength: 255, required: true });
    return h('form', {
        class: 'row add',
        onsubmit: async (event) => {
            event.preventDefault();
            const value = input.value.trim();
            if (!value) return;
            input.value = '';
            if (!(await submit(value))) {
                const current = document.querySelector(`[data-draft="${key}"]`);
                if (current && !current.value) current.value = value;
            }
        },
    }, input, h('button', { 'aria-label': buttonText }, buttonText));
}

function completeBox(card) {
    const locked = isLocked(state.lists.find((l) => l.id === card.list_id));
    return h('input', {
        type: 'checkbox',
        checked: card.completed,
        disabled: locked,
        title: locked ? 'Sadece yönetici onaylayabilir' : 'Tamamlandı olarak işaretle',
        'aria-label': `Tamamlandı: ${card.title}`,
        onclick: (event) => event.stopPropagation(),
        onchange: (event) => {
            event.target.disabled = true;
            mutate('POST', `/cards/${card.id}/complete`, { completed: event.target.checked, list_id: card.list_id });
        },
    });
}

// --- Çizim ---

/** Yeniden çizerken yazılmakta olan metinleri, odağı ve kaydırmayı korur. */
function render(keepDialog = false) {
    const active = document.activeElement?.dataset?.draft;
    const drafts = [...document.querySelectorAll('[data-draft]')].map((el) => [el.dataset.draft, el.value]);
    const scrolls = [...document.querySelectorAll('[data-scroll]')].map((el) => [el.dataset.scroll, el.scrollTop]);
    const dialogScroll = cardDialog.scrollTop;

    document.body.append($('#toast'));
    renderUsers();
    renderBoard();
    if (!keepDialog) renderCardDialog();
    if (rulesDialog.open) renderRules();

    for (const [key, value] of drafts) {
        const el = document.querySelector(`[data-draft="${key}"]`);
        if (el) el.value = value;
    }
    for (const [key, top] of scrolls) {
        const el = document.querySelector(`[data-scroll="${key}"]`);
        if (el) el.scrollTop = top;
    }
    cardDialog.scrollTop = dialogScroll;
    if (active) document.querySelector(`[data-draft="${active}"]`)?.focus();
}

function renderUsers() {
    const select = $('#user-select');
    select.replaceChildren(...state.users.map((u) => h('option', { value: u.id }, u.name)));
    select.value = state.me;
}

function renderBoard() {
    const scroll = boardEl.scrollLeft;
    const doneLists = state.lists.filter((l) => l.is_date);
    $('#done-count').textContent = doneLists.reduce((sum, l) => sum + l.cards.length, 0);
    document.querySelectorAll('[data-view]').forEach((a) => {
        if ((a.dataset.view !== '') === showingDone()) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
    });

    if (showingDone()) {
        // En yeni tarih solda.
        const key = (l) => l.name.slice(0, 10).split('.').reverse().join('');
        doneLists.sort((a, b) => key(b).localeCompare(key(a)));
        boardEl.replaceChildren(doneLists.length
            ? h('div', { class: 'lists' }, doneLists.map(renderList))
            : h('p', { class: 'empty' }, 'Henüz onaylanmış kart yok. Yönetici “Tamamlandı” listesindeki kartları onayladıkça burada görünür.'));
        boardEl.scrollLeft = scroll;
        return;
    }

    const lists = h('div', { class: 'lists' }, state.lists.filter((l) => !l.is_date).map(renderList));
    boardEl.replaceChildren(lists, h('div', { class: 'new-list' },
        addForm('new-list', 'Yeni liste adı', 'Ekle', (name) => mutate('POST', '/lists', { name }))));
    boardEl.scrollLeft = scroll;

    const drag = { animation: 150, ghostClass: 'ghost-card', delay: 150, delayOnTouchOnly: true, onStart: () => { dragging = true; } };
    Sortable.create(lists, {
        ...drag, handle: '.list-head', filter: 'button', preventOnFilter: false,
        onEnd: (e) => {
            dragging = false;
            if (e.oldIndex !== e.newIndex) mutate('POST', `/lists/${e.item.dataset.id}/move`, { index: e.newIndex });
        },
    });
    lists.querySelectorAll('.cards').forEach((el) => {
        const locked = isLocked(state.lists.find((l) => l.id === Number(el.dataset.id)));
        Sortable.create(el, {
            ...drag, group: { name: 'cards', pull: !locked, put: true }, sort: !locked,
            onEnd: (e) => {
                dragging = false;
                if (e.from === e.to && e.oldIndex === e.newIndex) return;
                mutate('POST', `/cards/${e.item.dataset.id}/move`, { list_id: Number(e.to.dataset.id), index: e.newIndex });
            },
        });
    });
}

/** Ana akış sıcak tonlardan yeşile ilerler; tarih listeleri en yeşil, özel listeler nötr mavi-mor tonlardadır. */
function listColor(list) {
    const flow = { 'Yapılacaklar': [12, 60, 92], 'Yapılıyor': [43, 70, 89], 'Yapıldı': [78, 45, 87], 'Tamamlandı': [125, 35, 86] };
    const [hue, sat, light] = flow[list.name] ?? (list.is_date ? [155, 38, 80] : [210 + (list.id * 37) % 110, 30, 91]);
    return `--list-bg:hsl(${hue} ${sat}% ${light}%);--list-line:hsl(${hue} ${sat}% ${light - 28}%)`;
}

function renderList(list) {
    return h('section', { class: 'list', 'data-id': list.id, style: listColor(list) },
        h('div', { class: 'list-head' },
            h('h2', {}, list.name),
            h('span', { class: 'count' }, String(list.cards.length)),
            !list.protected && !list.is_date && h('button', { class: 'icon', title: 'Yeniden adlandır', onclick: () => {
                const name = prompt('Liste adı', list.name)?.trim();
                if (name && name !== list.name) mutate('PUT', `/lists/${list.id}`, { name });
            } }, '✎'),
            !list.protected && !isLocked(list) && h('button', { class: 'icon', title: 'Listeyi sil', onclick: () => {
                if (confirm(`“${list.name}” ve içindeki ${list.cards.length} kart silinsin mi?`)) mutate('DELETE', `/lists/${list.id}`);
            } }, '✕')),
        h('div', { class: 'cards', 'data-id': list.id, 'data-scroll': `list-${list.id}` }, list.cards.map(renderCard)),
        !list.is_date && addForm(`card-${list.id}`, 'Kart ekle', '+', (title) => mutate('POST', `/lists/${list.id}/cards`, { title })));
}

function renderCard(card) {
    const all = items(card);
    const checked = all.filter((i) => i.checked).length;
    const today = new Date().toLocaleDateString('sv-SE');
    return h('article', {
        class: card.completed ? 'card done' : 'card', 'data-id': card.id, tabindex: 0,
        onclick: () => openCard(card.id),
        onkeydown: (e) => { if (e.key === 'Enter' && e.target === e.currentTarget) openCard(card.id); },
    },
        h('div', { class: 'card-top' }, completeBox(card), h('span', {}, card.title)),
        h('div', { class: 'badges' },
            card.due_date && h('span', { class: card.due_date < today && !card.completed ? 'badge late' : 'badge' }, `📅 ${card.due_date.split('-').reverse().join('.')}`),
            card.description && h('span', { title: 'Açıklama var' }, '☰'),
            card.comments.length > 0 && h('span', {}, `💬 ${card.comments.length}`),
            all.length > 0 && h('span', { class: checked === all.length ? 'badge ok' : 'badge' }, `☑ ${checked}/${all.length}`),
            h('span', { class: 'members' }, card.members.map((id) => avatar(user(id))))));
}

// --- Kart penceresi ---

function openCard(id) {
    openCardId = id;
    renderCardDialog();
    cardDialog.showModal();
    cardDialog.scrollTop = 0;
}

function renderCardDialog() {
    if (openCardId === null) return;
    const card = findCard(openCardId);
    if (!card) {
        cardDialog.close();
        return;
    }
    const list = state.lists.find((l) => l.id === card.list_id);
    // Alan kaydında pencere yeniden çizilmez; böylece o anda basılan buton kaybolmaz.
    const save = (field) => (e) => {
        if (field === 'title' && !e.target.value.trim()) {
            e.target.value = findCard(card.id)?.title ?? card.title;
            return;
        }
        mutate('PUT', `/cards/${card.id}`, { [field]: e.target.value || null }, { keepDialog: true });
    };

    cardDialog.replaceChildren(
        h('div', { class: 'dialog-head' },
            completeBox(card),
            h('input', { value: card.title, maxlength: 255, 'aria-label': 'Başlık', onchange: save('title') }),
            h('button', { class: 'icon', 'aria-label': 'Kapat', onclick: () => cardDialog.close() }, '✕')),
        h('div', { class: 'muted' }, `Liste: ${list.name}`),

        h('div', { class: 'card-columns' },
            h('div', { class: 'card-main', 'data-scroll': 'card-main' },
                h('h3', {}, 'Üyeler'),
                h('div', { class: 'row' }, state.users.map((u) => h('button', {
                    class: 'member-toggle', 'aria-pressed': String(card.members.includes(u.id)),
                    onclick: () => mutate('POST', `/cards/${card.id}/members/${u.id}`),
                }, avatar(u), u.name))),

                h('h3', {}, 'Bitiş tarihi'),
                h('input', { type: 'date', value: card.due_date ?? '', 'aria-label': 'Bitiş tarihi', style: 'width:auto', onchange: save('due_date') }),

                h('h3', {}, 'Açıklama'),
                h('textarea', { value: card.description ?? '', maxlength: 10000, 'aria-label': 'Açıklama', onchange: save('description') }),

                card.checklists.map(renderChecklist),
                h('h3', {}, 'Yeni kontrol listesi'),
                addForm('new-checklist', 'Kontrol listesi adı', 'Ekle', (name) => mutate('POST', `/cards/${card.id}/checklists`, { name })),

                list.is_date && isAdmin() && h('div', { style: 'margin-top:20px' }, h('button', { onclick: () => {
                    const target = state.lists.find((l) => l.name === 'Tamamlandı');
                    mutate('POST', `/cards/${card.id}/move`, { list_id: target.id, index: 0 });
                } }, '↩ Tamamlandı listesine geri al')),
                !isLocked(list) && h('div', { style: 'margin-top:20px' }, h('button', { class: 'danger', onclick: () => {
                    if (confirm('Kart silinsin mi?')) mutate('DELETE', `/cards/${card.id}`);
                } }, 'Kartı sil'))),

            h('aside', { class: 'card-comments', 'data-scroll': 'card-comments' },
                h('h3', {}, 'Yorumlar'),
                addForm(`comment-${card.id}`, 'Yorum yaz', 'Gönder', (body) => mutate('POST', `/cards/${card.id}/comments`, { body })),
                card.comments.map((c) => {
                    const author = user(c.user_id) ?? { name: '⚡ Otomasyon', color: '#5b6b75' };
                    return h('div', { class: 'comment' }, avatar(author),
                        h('div', {}, h('strong', {}, author.name), ' ', h('span', { class: 'muted' }, c.at), h('p', {}, c.body)));
                }))));
}

function renderChecklist(checklist) {
    const done = checklist.items.filter((i) => i.checked).length;
    const percent = checklist.items.length ? (done / checklist.items.length) * 100 : 0;
    return h('div', {},
        h('div', { class: 'checklist-head' },
            h('h3', {}, `${checklist.name} (${done}/${checklist.items.length})`),
            h('button', { class: 'icon', title: 'Kontrol listesini sil', onclick: () => {
                if (confirm(`“${checklist.name}” silinsin mi?`)) mutate('DELETE', `/checklists/${checklist.id}`);
            } }, '✕')),
        h('div', { class: 'bar' }, h('i', { style: `width:${percent}%` })),
        checklist.items.map((item) => h('div', { class: item.checked ? 'item checked' : 'item' },
            h('input', { type: 'checkbox', checked: item.checked, 'aria-label': item.name,
                onchange: (e) => mutate('PUT', `/items/${item.id}`, { checked: e.target.checked }) }),
            h('span', {}, item.name),
            h('button', { class: 'icon', title: 'Maddeyi sil', onclick: () => mutate('DELETE', `/items/${item.id}`) }, '✕'))),
        addForm(`item-${checklist.id}`, 'Madde ekle', 'Ekle', (name) => mutate('POST', `/checklists/${checklist.id}/items`, { name })));
}

// --- Otomasyon penceresi ---

function ruleText(rule) {
    const where = rule.list ? `“${rule.list}” listesindeki` : 'Herhangi bir listedeki';
    const checklist = { complete: 'kontrol listesi tamam olan', none: 'kontrol listesi olmayan', incomplete: 'kontrol listesinde eksik olan' }[rule.checklist];
    const who = rule.by === 'me' ? 'yönetici' : 'herhangi biri';
    const actions = [
        rule.move_to && `“${rule.move_to}” listesinin en üstüne taşı`,
        rule.reset && 'tamamlanmadı yap',
        rule.comment && `“${rule.comment}” yorumunu yaz`,
    ].filter(Boolean).join(', ');
    return `${where} ${checklist} kartı ${who} tamamlandı işaretlerse → ${actions}`;
}

function renderRules() {
    rulesDialog.replaceChildren(
        h('div', { class: 'dialog-head' }, h('h2', {}, 'Otomasyonlar'),
            h('button', { class: 'icon', 'aria-label': 'Kapat', onclick: () => rulesDialog.close() }, '✕')),
        h('ul', { class: 'rules' }, state.rules.map((r) => h('li', {}, h('strong', {}, `${r.id}. `), ruleText(r)))),
        h('h3', {}, 'Son çalışanlar'),
        state.logs.length
            ? h('ul', { class: 'rules' }, state.logs.map((l) => h('li', {}, `Kural ${l.rule_id} · ${l.message} `, h('span', { class: 'muted' }, `${l.user ?? ''} · ${l.at}`))))
            : h('p', { class: 'muted' }, 'Henüz çalışan kural yok.'));
}

// --- Başlangıç ---

$('#automations-button').onclick = () => { renderRules(); rulesDialog.showModal(); };
$('#user-select').onchange = async (e) => {
    try {
        await request('POST', `/switch-user/${e.target.value}`);
        location.reload();
    } catch (error) {
        toast(error.message);
    }
};
for (const dialog of [cardDialog, rulesDialog]) {
    dialog.addEventListener('mousedown', (e) => {
        if (e.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        const outside = e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom;
        if (outside) dialog.close();
    });
    dialog.addEventListener('close', () => document.body.append($('#toast')));
}
cardDialog.addEventListener('close', () => {
    openCardId = null;
    cardDialog.replaceChildren();
});

async function poll() {
    const busy = () => dragging || document.activeElement?.matches('input:not([type=checkbox]), textarea, select');
    if (busy()) return;
    const before = changeCount;
    try {
        const fresh = await request('GET', '/board');
        if (before !== changeCount || busy()) return;
        const same = JSON.stringify({ ...fresh, notice: null }) === JSON.stringify({ ...state, notice: null });
        if (fresh.me !== state?.me && state) return location.reload();
        state = fresh;
        if (!same) render();
    } catch {
        if (!state) toast('Pano yüklenemedi.');
    }
}

window.addEventListener('hashchange', () => {
    if (!state) return;
    renderBoard();
    boardEl.scrollLeft = 0;
});

poll();
setInterval(poll, 4000); // diğer kullanıcıların değişikliklerini al
