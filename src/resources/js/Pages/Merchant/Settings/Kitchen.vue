<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import KitchenTargets from './KitchenTargets.vue';
import { ApiError } from '@/lib/api';
import { getKitchen, getKitchenBranch, previewPolicy, savePolicy, saveRouting, previewRouting, activateKitchen, type Settings, type Detail, type Bundle, type Source, type Mode, type PolicyInput, type PolicyPreview, type RoutingPreview, type RoutingRule } from '@/lib/api/kitchen';
const { locale } = useI18n();
const tx = (en: string, ar: string) => locale.value === 'ar' ? ar : en;
const settings = ref<Settings | null>(null), detail = ref<Detail | null>(null), branchId = ref('');
const busy = ref(false), loading = ref(true), error = ref(''), success = ref('');
const draft = ref<Bundle | null>(null), dirty = ref(false);
const preview = ref<PolicyPreview | null>(null), routePreview = ref<RoutingPreview | null>(null);
const policy = reactive<PolicyInput>({ branch_uuid: null, source: 'qr_web', mode: 'manual' });
const sourceNames: Record<Source, [string, string]> = { staff: ['Staff orders (till + handheld)', 'طلبات الموظفين (الجهاز الرئيسي والمحمول)'], qr_web: ['QR / web orders', 'طلبات رمز QR والموقع'], customer_tablet: ['Customer tablet', 'جهاز العميل اللوحي'] };
const modeName = (m: Mode) => m === 'manual' ? tx('Require staff approval', 'يتطلب موافقة الموظف') : tx('Send directly to kitchen', 'إرسال مباشر إلى المطبخ');
const sourceName = (s: Source) => tx(...sourceNames[s]);
const sources: Source[] = ['staff', 'qr_web', 'customer_tablet'];
const editable = computed(() => settings.value?.can_manage === true && !busy.value && !loading.value);
const selectedProducts = ref<number[]>([]), deviceId = ref(''), isolation = ref(''), confirm = ref(false);
let loadGeneration = 0;
const copyBundle = (b: Bundle): Bundle => { const copy = JSON.parse(JSON.stringify(b)) as Bundle; delete copy.policies; copy.display ??= { fallback_minutes: 15 }; return copy; };
function applyDetail(value: Detail) { detail.value = value; draft.value = copyBundle(value.bundle); dirty.value = false; routePreview.value = null; }
function message(e: unknown) {
    if (e instanceof ApiError) {
        if (e.status === 409) return tx('Settings changed since you loaded them. Discard or keep your draft, then refresh and preview again.', 'تغيرت الإعدادات. احتفظ بمسودتك أو تجاهلها ثم حدّث الصفحة وراجع المعاينة.');
        if (e.status === 403) return tx('Your account cannot change these branch settings.', 'حسابك غير مخوّل لتغيير إعدادات هذا الفرع.');
        if (e.status === 503) return tx('Kitchen setup is not enabled on this server yet.', 'إعداد المطبخ غير مفعّل على هذا الخادم بعد.');
        return e.message;
    }
    return tx('Could not connect. Your draft has been kept. Try again.', 'تعذر الاتصال. تم الاحتفاظ بمسودتك. حاول مرة أخرى.');
}
async function run(task: () => Promise<void>) { if (busy.value) return; busy.value = true; error.value = ''; success.value = ''; try { await task(); } catch (e) { error.value = message(e); } finally { busy.value = false; } }
async function loadBranch() {
    const generation = ++loadGeneration; loading.value = true; error.value = ''; detail.value = null; draft.value = null; preview.value = null; selectedProducts.value = []; deviceId.value = ''; confirm.value = false;
    try { const response = await getKitchenBranch(branchId.value); if (generation === loadGeneration) { applyDetail(response.data); policy.branch_uuid = branchId.value; policy.mode = response.data.policies[policy.source]; } }
    catch (e) { if (generation === loadGeneration) error.value = message(e); }
    finally { if (generation === loadGeneration) loading.value = false; }
}
async function refresh() {
    await run(async () => { settings.value = (await getKitchen()).data; if (!branchId.value) branchId.value = settings.value.branches[0]?.uuid ?? ''; if (branchId.value) await loadBranch(); });
    loading.value = false;
}
onMounted(refresh);
watch(() => [policy.source, policy.mode, policy.branch_uuid], () => { preview.value = null; });
function edited() { dirty.value = true; routePreview.value = null; }
function addArea() { draft.value?.areas.push({ id: crypto.randomUUID(), name: tx('Preparation area', 'منطقة تحضير') }); edited(); }
function addDestination(type: 'printer' | 'screen') { draft.value?.destinations.push({ id: crypto.randomUUID(), name: type === 'printer' ? tx('Kitchen printer', 'طابعة المطبخ') : tx('Kitchen display', 'شاشة المطبخ'), type, paused: false, ...(type === 'printer' ? { address: '', port: 9100, profile: 'escpos-unverified' } : {}) }); edited(); }
function removeTarget(id: string, kind: 'areas' | 'destinations') {
    if (!draft.value) return;
    if (kind === 'areas') draft.value.areas = draft.value.areas.filter(x => x.id !== id);
    else draft.value.destinations = draft.value.destinations.filter(x => x.id !== id);
    for (const route of [draft.value.fallback, ...draft.value.rules]) route[kind] = route[kind].filter(v => v !== id);
    draft.value.all_items = draft.value.all_items.filter(v => v !== id); edited();
}
function addRule() { if (!draft.value) return; draft.value.rules.push({ kind: 'item', reference_id: detail.value?.catalogue.products[0]?.id ?? 0, areas: [], destinations: [] }); edited(); }
function kindChanged(rule: RoutingRule) { rule.reference_id = (rule.kind === 'item' ? detail.value?.catalogue.products : detail.value?.catalogue.categories)?.[0]?.id ?? 0; edited(); }
async function reviewPolicy() { await run(async () => { preview.value = (await previewPolicy({ ...policy })).data; }); }
async function commitPolicy() { if (!preview.value) return; const input = { ...policy }, hash = preview.value.preview_hash; await run(async () => { settings.value = (await savePolicy(input, hash)).data; preview.value = null; if (branchId.value) await loadBranch(); success.value = tx('Release rules saved. Branches apply them after synchronization. Waiting approvals stay waiting.', 'تم حفظ القواعد. تُطبّق بعد مزامنة الفروع. الطلبات المنتظرة تبقى بانتظار الموافقة.'); }); }
async function commitRouting() { if (!draft.value || !detail.value) return; const bundle = copyBundle(draft.value), version = detail.value.desired_version; await run(async () => { applyDetail((await saveRouting(branchId.value, bundle, version)).data); settings.value = (await getKitchen()).data; success.value = tx('Routing saved. Waiting for the branch to acknowledge it.', 'تم حفظ التوجيه. بانتظار تأكيد الفرع.'); }); }
async function testRouting() { if (!draft.value || !detail.value) return; await run(async () => { routePreview.value = (await previewRouting(branchId.value, copyBundle(draft.value!), detail.value!.desired_version, selectedProducts.value)).data; }); }
async function optIn() { if (!detail.value || !confirm.value) return; await run(async () => { applyDetail((await activateKitchen(branchId.value, detail.value!.desired_version, deviceId.value, isolation.value)).data); success.value = tx('Opt-in recorded. The branch must acknowledge its configuration before the kitchen becomes active.', 'تم تسجيل التفعيل. يجب أن يؤكد الفرع إعداداته قبل تشغيل المطبخ.'); confirm.value = false; }); }
const deliveryStateName = (state: string) => {
    const labels: Record<string, [string, string]> = {
        queued: ['Waiting to send', 'بانتظار الإرسال'], paused: ['Paused', 'موقوف مؤقتاً'], claimed: ['Preparing delivery', 'تجهيز الإرسال'], sending: ['Sending', 'جارٍ الإرسال'],
        sent_unconfirmed: ['Sent; paper unconfirmed', 'تم الإرسال؛ الورق غير مؤكد'], uncertain: ['Needs staff review', 'يتطلب مراجعة الموظف'],
        failed_before_send: ['Connection failed before printing', 'فشل الاتصال قبل الطباعة'], confirmed: ['Confirmed', 'مؤكد'], cancelled: ['Cancelled', 'ملغى'],
    };
    return labels[state] ? tx(...labels[state]) : state;
};
const destinationName = (id: string) => draft.value?.destinations.find(d => d.id === id)?.name ?? id;
const areaName = (id: string) => draft.value?.areas.find(a => a.id === id)?.name ?? id;
</script>

<template>
    <MerchantLayout>
        <main class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6" :dir="locale === 'ar' ? 'rtl' : 'ltr'">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div><h1 class="text-2xl font-semibold text-slate-900">{{ tx('Kitchen setup', 'إعداد المطبخ') }}</h1><p class="mt-1 text-sm text-slate-600">{{ tx('Choose when orders reach the kitchen and where each item goes.', 'اختر متى تصل الطلبات إلى المطبخ وإلى أين يذهب كل صنف.') }}</p></div>
                <button class="secondary" :disabled="busy || dirty" @click="refresh">{{ tx('Refresh status', 'تحديث الحالة') }}</button>
            </header>
            <p v-if="error" role="alert" class="rounded-xl bg-rose-50 p-4 text-rose-800">{{ error }}</p>
            <p v-if="success" role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ success }}</p>
            <p v-if="loading" role="status">{{ tx('Loading kitchen settings…', 'جارٍ تحميل إعدادات المطبخ…') }}</p>
            <p v-if="settings && !settings.branches.length" class="panel">{{ tx('No branches are available to your account.', 'لا توجد فروع متاحة لحسابك.') }}</p>
            <template v-if="settings && settings.branches.length">
                <div class="panel flex flex-wrap items-center gap-4"><label for="kitchen-branch" class="font-medium">{{ tx('Branch', 'الفرع') }}</label>
                    <select id="kitchen-branch" v-model="branchId" :disabled="busy || dirty" @change="loadBranch"><option v-for="b in settings.branches" :key="b.uuid" :value="b.uuid">{{ locale === 'ar' && b.name_ar ? b.name_ar : b.name }}</option></select>
                    <span v-if="dirty" class="text-sm text-amber-800">{{ tx('Unsaved routing changes', 'تغييرات توجيه غير محفوظة') }}</span>
                    <button v-if="dirty" class="secondary" :disabled="busy" @click="loadBranch">{{ tx('Discard draft', 'تجاهل المسودة') }}</button>
                </div>
                <template v-if="detail && draft">
                    <section v-if="draft.display" class="panel space-y-3">
                        <h2>{{ tx('Kitchen display timing', 'توقيت شاشة المطبخ') }}</h2>
                        <label class="flex flex-wrap items-center gap-3">{{ tx('Fallback target (minutes)', 'الوقت الافتراضي المستهدف (دقائق)') }}
                            <input v-model.number="draft.display.fallback_minutes" type="number" min="1" max="240" :disabled="!editable" @input="edited">
                        </label>
                        <p class="text-sm text-slate-600">{{ tx('Used when a card has no saved item cooking time. Otherwise the longest saved cooking time applies. Save with routing below; existing orders keep their timing.', 'يُستخدم عند عدم وجود وقت طهي محفوظ للأصناف. وإلا يُستخدم أطول وقت محفوظ. احفظ مع التوجيه أدناه؛ تحتفظ الطلبات الحالية بتوقيتها.') }}</p>
                    </section>
                    <section class="panel flex flex-wrap items-center justify-between gap-4" aria-label="Synchronization status">
                        <div><span class="rounded-full px-3 py-1 text-sm font-medium" :class="detail.mode === 'legacy' ? 'bg-slate-100' : detail.pending ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900'">{{ detail.mode === 'legacy' ? tx('Current kitchen system', 'نظام المطبخ الحالي') : detail.mode === 'suspended' ? tx('Suspended', 'موقوف') : detail.pending ? tx('Pending synchronization', 'بانتظار المزامنة') : tx('Applied', 'مطبّق') }}</span>
                            <p class="mt-3 text-sm text-slate-600">{{ tx('Saved version', 'النسخة المحفوظة') }} {{ detail.desired_version }} · {{ tx('Applied version', 'النسخة المطبّقة') }} {{ detail.applied_version }}</p></div>
                        <p class="max-w-lg text-sm text-slate-600">{{ detail.mode === 'legacy' ? tx('Saving settings prepares the new kitchen. Your branch keeps its current behavior until you explicitly opt in.', 'حفظ الإعدادات يجهّز المطبخ الجديد. يحتفظ الفرع بسلوكه الحالي حتى تفعّله صراحةً.') : tx('Saved changes take effect after the assigned POS device acknowledges them. Existing orders keep their recorded rules.', 'تسري التغييرات بعد تأكيد جهاز نقاط البيع المعيّن. تحتفظ الطلبات الحالية بقواعدها المسجلة.') }}</p>
                    </section>
                    <section class="panel space-y-4">
                        <h2>{{ tx('1. Order release rules', '١. قواعد إرسال الطلبات') }}</h2>
                        <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr class="border-b text-slate-500"><th>{{ tx('Order source', 'مصدر الطلب') }}</th><th>{{ tx('Saved rule', 'القاعدة المحفوظة') }}</th><th>{{ tx('Applied rule', 'القاعدة المطبّقة') }}</th></tr></thead>
                            <tbody><tr v-for="source in sources" :key="source" class="border-b border-slate-100"><td>{{ sourceName(source) }}</td><td>{{ modeName(detail.policies[source]) }}<span class="block text-xs text-slate-500">{{ detail.overrides[source] ? tx('Branch override', 'إعداد خاص بالفرع') : tx('Company default', 'الإعداد الافتراضي للشركة') }}</span></td><td>{{ detail.applied_policies ? modeName(detail.applied_policies[source]) : tx('Current system', 'النظام الحالي') }}</td></tr></tbody></table></div>
                        <fieldset :disabled="!editable || dirty" class="grid gap-4 md:grid-cols-3">
                            <label>{{ tx('Source', 'المصدر') }}<select :aria-label="tx('Source', 'المصدر')" v-model="policy.source"><option v-for="source in sources" :key="source" :value="source">{{ sourceName(source) }}</option></select></label>
                            <label>{{ tx('Release rule', 'قاعدة الإرسال') }}<select :aria-label="tx('Release rule', 'قاعدة الإرسال')" v-model="policy.mode"><option value="manual">{{ modeName('manual') }}</option><option value="immediate">{{ modeName('immediate') }}</option><option disabled>{{ tx('After full payment — coming later', 'بعد السداد الكامل — لاحقاً') }}</option></select></label>
                            <label>{{ tx('Apply to', 'تطبيق على') }}<select :aria-label="tx('Apply to', 'تطبيق على')" v-model="policy.branch_uuid"><option :value="branchId">{{ tx('This branch', 'هذا الفرع') }}</option><option v-if="settings.can_apply_all" :value="null">{{ tx('All my branches', 'جميع فروعي') }}</option></select></label>
                        </fieldset>
                        <p class="text-sm text-slate-500">{{ tx('Only the selected source changes. Printer addresses stay specific to each branch. Waiting orders are never approved by changing a rule.', 'يتغير المصدر المحدد فقط. تبقى عناوين الطابعات خاصة بكل فرع. لا تُعتمد الطلبات المنتظرة عند تغيير القاعدة.') }}</p>
                        <button class="primary" :disabled="!editable || dirty" @click="reviewPolicy">{{ tx('Preview affected branches', 'معاينة الفروع المتأثرة') }}</button>
                        <div v-if="preview" class="space-y-3 rounded-xl border border-teal-200 bg-teal-50 p-4" role="region" :aria-label="tx('Policy preview', 'معاينة القاعدة')">
                            <p class="font-medium">{{ sourceName(preview.source) }} → {{ modeName(preview.mode) }}</p>
                            <ul class="space-y-1 text-sm"><li v-for="b in preview.branches" :key="b.uuid">{{ b.name }}: {{ modeName(b.before) }} → {{ modeName(preview.mode) }} <span v-if="b.override_removed">· {{ tx('branch override removed', 'إزالة الإعداد الخاص بالفرع') }}</span></li></ul>
                            <p v-if="preview.future_branches" class="text-sm">{{ tx('Future branches will inherit this company default.', 'سترث الفروع المستقبلية هذا الإعداد الافتراضي.') }}</p>
                            <button class="primary" :disabled="!editable || dirty" @click="commitPolicy">{{ tx('Confirm and save rule', 'تأكيد وحفظ القاعدة') }}</button>
                        </div>
                    </section>
                    <section class="panel space-y-4" @input="edited" @change="edited">
                        <h2>{{ tx('2. Preparation areas and destinations', '٢. مناطق التحضير والوجهات') }}</h2>
                        <p class="text-sm text-slate-600">{{ tx('Areas represent required cooking work. A copy to a printer or pass screen does not add another Done requirement.', 'تمثل المناطق عمل التحضير المطلوب. لا تتطلب النسخة المرسلة إلى طابعة أو شاشة تسليم إكمالاً إضافياً.') }}</p>
                        <fieldset :disabled="!editable" class="space-y-3">
                            <div v-for="(area, index) in draft.areas" :key="area.id" class="flex gap-3"><label class="flex-1">{{ tx('Area name', 'اسم المنطقة') }} {{ index + 1 }}<input v-model="area.name" maxlength="100"></label><button class="danger self-end" @click="removeTarget(area.id, 'areas')">{{ tx('Remove', 'إزالة') }}</button></div>
                            <button class="secondary" @click="addArea">{{ tx('Add preparation area', 'إضافة منطقة تحضير') }}</button>
                            <div v-for="(d, index) in draft.destinations" :key="d.id" class="space-y-3 rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-wrap items-end gap-3"><label class="min-w-40 flex-1">{{ d.type === 'printer' ? tx('Printer name', 'اسم الطابعة') : tx('Screen name', 'اسم الشاشة') }} {{ index + 1 }}<input v-model="d.name" maxlength="100"></label><label class="flex items-center gap-2 text-sm"><input v-model="d.paused" type="checkbox">{{ tx('Pause delivery', 'إيقاف الإرسال مؤقتاً') }}</label><button class="danger" @click="removeTarget(d.id, 'destinations')">{{ tx('Remove', 'إزالة') }}</button></div>
                                <div v-if="d.type === 'printer'" class="grid gap-3 sm:grid-cols-3"><label>{{ tx('LAN IP address', 'عنوان IP في الشبكة المحلية') }}<input v-model="d.address" dir="ltr" placeholder="192.168.100.173"></label><label>{{ tx('Port', 'المنفذ') }}<input v-model.number="d.port" type="number" min="1" max="65535" dir="ltr"></label><label>{{ tx('Printer profile', 'ملف الطابعة') }}<select :aria-label="tx('Printer profile', 'ملف الطابعة')" v-model="d.profile"><option value="sunmi-nt311-raster-80-v1">{{ tx('SUNMI NT311 — 80 mm Arabic/English', 'SUNMI NT311 — 80 مم عربي/إنجليزي') }}</option><option value="sunmi-nt320-receipt-raster-80-v1">{{ tx('SUNMI NT320 — receipt paper, 80 mm Arabic/English', 'SUNMI NT320 — ورق إيصالات 80 مم عربي/إنجليزي') }}</option><option value="escpos-unverified">{{ tx('ESC/POS — needs printer testing', 'ESC/POS — يتطلب اختبار الطابعة') }}</option></select></label></div>
                            </div>
                            <div class="flex flex-wrap gap-2"><button class="secondary" @click="addDestination('printer')">{{ tx('Add Ethernet printer', 'إضافة طابعة إيثرنت') }}</button><button class="secondary" @click="addDestination('screen')">{{ tx('Add kitchen screen', 'إضافة شاشة مطبخ') }}</button></div>
                        </fieldset>
                        <p class="text-xs text-slate-500">{{ tx('No test print is sent from this page. Pausing keeps queued work; saving a new address never resends existing jobs.', 'لا ترسل هذه الصفحة طباعة تجريبية. الإيقاف يحتفظ بالطابور؛ حفظ عنوان جديد لا يعيد إرسال المهام الحالية.') }}</p>
                    </section>

                    <section class="panel space-y-4" @input="edited" @change="edited">
                        <h2>{{ tx('3. Item routing', '٣. توجيه الأصناف') }}</h2>
                        <p class="text-sm text-slate-600">{{ tx('An item rule replaces its category rule. All-items copies are added once. Choose multiple printers when needed.', 'تحل قاعدة الصنف محل قاعدة الفئة. تضاف نسخ جميع الأصناف مرة واحدة. اختر عدة طابعات عند الحاجة.') }}</p>
                        <fieldset :disabled="!editable" class="rounded-lg border border-slate-200 p-3"><legend>{{ tx('Copy every item to', 'نسخ كل صنف إلى') }}</legend><label v-for="d in draft.destinations" :key="d.id" class="me-4 inline-flex items-center gap-2 text-sm"><input v-model="draft.all_items" type="checkbox" :value="d.id">{{ d.name }}</label><p v-if="!draft.destinations.length" class="text-sm text-slate-500">{{ tx('Add a destination above.', 'أضف وجهة أعلاه.') }}</p></fieldset>
                        <div v-for="(rule, index) in draft.rules" :key="index" class="space-y-3 rounded-xl bg-slate-50 p-4">
                            <fieldset :disabled="!editable" class="flex flex-wrap items-end gap-3"><label>{{ tx('Rule type', 'نوع القاعدة') }}<select :aria-label="tx('Rule type', 'نوع القاعدة')" v-model="rule.kind" @change="kindChanged(rule)"><option value="item">{{ tx('Specific item', 'صنف محدد') }}</option><option value="category">{{ tx('Category', 'فئة') }}</option></select></label><label class="min-w-40 flex-1">{{ tx('Item or category', 'الصنف أو الفئة') }}<select :aria-label="tx('Item or category', 'الصنف أو الفئة')" v-model.number="rule.reference_id"><option v-for="item in rule.kind === 'item' ? detail.catalogue.products : detail.catalogue.categories" :key="item.id" :value="item.id">{{ locale === 'ar' && item.name_ar ? item.name_ar : item.name }}</option></select></label><button class="danger" @click="draft.rules.splice(index, 1); edited()">{{ tx('Remove rule', 'إزالة القاعدة') }}</button></fieldset>
                            <KitchenTargets :model-value="rule" :areas="draft.areas" :destinations="draft.destinations" :disabled="!editable" :label="tx('Send this item or category to', 'إرسال هذا الصنف أو الفئة إلى')" @update:model-value="Object.assign(rule, $event); edited()" />
                        </div>
                        <button class="secondary" :disabled="!editable || !detail.catalogue.products.length" @click="addRule">{{ tx('Add item or category rule', 'إضافة قاعدة صنف أو فئة') }}</button>
                        <KitchenTargets v-model="draft.fallback" :areas="draft.areas" :destinations="draft.destinations" :disabled="!editable" :label="tx('Fallback for items without preparation work', 'الوجهة الاحتياطية للأصناف دون تحضير محدد')" @update:model-value="edited" />
                        <p v-if="!draft.fallback.areas.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ tx('No fallback area selected. Unrouted items will be held as Needs routing until staff resolve them.', 'لم تُحددّد منطقة احتياطية. ستبقى الأصناف غير الموجهة بحالة تحتاج إلى توجيه حتى يعالجها الموظفون.') }}</p>
                    </section>
                    <section class="panel space-y-4">
                        <h2>{{ tx('4. Preview and save routing', '٤. معاينة وحفظ التوجيه') }}</h2>
                        <label>{{ tx('Select items to preview (no order or print is created)', 'اختر أصنافاً للمعاينة (لا يُنشأ طلب أو طباعة)') }}<select :aria-label="tx('Select items to preview (no order or print is created)', 'اختر أصنافاً للمعاينة (لا يُنشأ طلب أو طباعة)')" v-model="selectedProducts" multiple size="4" :disabled="!editable"><option v-for="p in detail.catalogue.products" :key="p.id" :value="p.id">{{ locale === 'ar' && p.name_ar ? p.name_ar : p.name }}</option></select></label>
                        <div class="flex flex-wrap gap-2"><button class="secondary" :disabled="!editable || !selectedProducts.length" @click="testRouting">{{ tx('Preview routing', 'معاينة التوجيه') }}</button><button class="primary" :disabled="!editable || !dirty" @click="commitRouting">{{ tx('Save branch routing', 'حفظ توجيه الفرع') }}</button></div>
                        <div v-if="routePreview" class="space-y-3 rounded-xl bg-slate-50 p-4" role="region" :aria-label="tx('Routing preview', 'معاينة التوجيه')">
                            <p class="font-medium">{{ tx('Required preparation', 'التحضير المطلوب') }}</p><ul class="text-sm"><li v-for="(work, i) in routePreview.work" :key="i">{{ work.line.name }} → {{ areaName(work.area_uuid) }}</li></ul>
                            <div v-for="(lines, destination) in routePreview.copies" :key="destination"><p class="font-medium">{{ destinationName(String(destination)) }}</p><ul class="text-sm"><li v-for="line in lines" :key="line.line_uuid">1 × {{ line.name }}</li></ul></div>
                            <p v-if="routePreview.needs_routing.length" class="text-amber-900">{{ tx('Needs routing', 'يحتاج إلى توجيه') }}: {{ routePreview.needs_routing.length }}</p>
                            <p v-else class="text-emerald-800">{{ tx('All preview items have preparation work.', 'كل أصناف المعاينة لها تحضير محدد.') }}</p>
                        </div>
                    </section>
                    <section class="panel space-y-4">
                        <h2>{{ tx('Branch activation', 'تفعيل الفرع') }}</h2>
                        <p v-if="!detail.activation_available" class="text-sm text-slate-600">{{ tx('Activation is unavailable until the kitchen apps and controlled rollout are ready. You can prepare and save the settings now.', 'التفعيل غير متاح حتى تصبح تطبيقات المطبخ والإطلاق المنظم جاهزة. يمكنك تجهيز الإعدادات وحفظها الآن.') }}</p>
                        <fieldset v-else-if="detail.mode === 'legacy' || detail.mode === 'suspended'" :disabled="!editable || dirty" class="space-y-3">
                            <label>{{ tx('Assigned POS device', 'جهاز نقاط البيع المعيّن') }}<select :aria-label="tx('Assigned POS device', 'جهاز نقاط البيع المعيّن')" v-model="deviceId"><option value="">{{ tx('Choose one till or handheld', 'اختر جهازاً رئيسياً أو محمولاً واحداً') }}</option><option v-for="d in detail.devices" :key="d.uuid" :value="d.uuid" :disabled="!d.enrolled">{{ d.name || d.type }}{{ d.enrolled ? '' : tx(' — not enrolled', ' — غير مسجّل') }}</option></select></label>
                            <label>{{ tx('Record how previous printer executors were stopped or isolated', 'سجّل كيفية إيقاف أو عزل أجهزة الطباعة السابقة') }}<textarea v-model="isolation" minlength="12" maxlength="1000" rows="2" /></label>
                            <label class="flex gap-2 text-sm"><input v-model="confirm" type="checkbox">{{ tx('I have stopped previous printer executors and reconciled pending print work for this branch.', 'أوقفت أجهزة الطباعة السابقة وراجعت أعمال الطباعة المنتظرة لهذا الفرع.') }}</label>
                            <button class="primary" :disabled="!confirm || !deviceId || isolation.trim().length < 12" @click="optIn">{{ tx('Opt in this branch', 'تفعيل هذا الفرع') }}</button>
                        </fieldset>
                        <p v-else class="text-sm text-slate-600">{{ tx('The assigned device is already awaiting synchronization or active.', 'الجهاز المعيّن بانتظار المزامنة أو مفعّل بالفعل.') }}</p>
                    </section>
                    <section class="panel space-y-3"><h2>{{ tx('Delivery status', 'حالة الإرسال') }}</h2>
                        <p v-if="!Object.keys(detail.delivery_counts).length" class="text-sm text-slate-500">{{ tx('No kitchen deliveries recorded yet.', 'لا توجد عمليات إرسال للمطبخ بعد.') }}</p>
                        <div class="flex flex-wrap gap-2"><span v-for="(count, state) in detail.delivery_counts" :key="state" class="rounded-lg bg-slate-100 px-3 py-2 text-sm">{{ deliveryStateName(String(state)) }}: {{ count }}</span></div>
                        <p class="text-xs text-slate-500">{{ tx('Transport success is not proof of printed paper. Uncertain jobs need staff review on the assigned POS device.', 'نجاح النقل ليس دليلاً على الطباعة الورقية. تحتاج المهام غير المؤكدة إلى مراجعة الموظف على الجهاز المعيّن.') }}</p>
                    </section>
                </template>
            </template>
        </main>
    </MerchantLayout>
</template>
<style scoped>
@reference '../../../../css/app.css';
.panel { @apply rounded-2xl border border-slate-200 bg-white p-5 shadow-sm; }
h2 { @apply text-lg font-semibold text-slate-900; }
label { @apply text-sm text-slate-700; }
select, input:not([type=checkbox]), textarea { @apply mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900; }
input[type=checkbox] { @apply h-4 w-4 accent-teal-700; }
.primary { @apply rounded-lg bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800; }
.secondary { @apply rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50; }
.danger { @apply rounded-lg px-3 py-2 text-sm text-rose-700 hover:bg-rose-50; }
button:disabled, fieldset:disabled, select:disabled { @apply cursor-not-allowed opacity-50; }
th, td { @apply p-3 text-start; }
</style>
