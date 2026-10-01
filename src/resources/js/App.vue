<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { RouterView } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { accountAccess } from '@/stores/accountAccess';
import { authState } from '@/stores/auth';
import { apiGet } from '@/lib/api';

const { locale } = useI18n();
let timer: ReturnType<typeof setInterval> | undefined;
let checking = false;
async function checkAccount(): Promise<void> {
    if (!authState.user || accountAccess.suspended || checking || document.visibilityState !== 'visible') return;
    checking = true;
    try { await apiGet('/auth/access', { skipAuthInterceptor: true }); }
    catch { /* The shared API client records suspension; transient failures retry later. */ }
    finally { checking = false; }
}
onMounted(() => {
    timer = setInterval(() => void checkAccount(), 60000);
    window.addEventListener('focus', checkAccount);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
    window.removeEventListener('focus', checkAccount);
});
/**
 * LAUNCH-P1 P1-13: say which block applies — suspended, closed or
 * unavailable — instead of always "Account suspended".
 */
const blockedTitle = computed(() => {
    const ar = locale.value === 'ar';
    switch (accountAccess.reason) {
        case 'company_inactive': return ar ? 'الحساب مغلق' : 'Account closed';
        case 'company_unavailable': return ar ? 'الحساب غير متاح' : 'Account unavailable';
        default: return ar ? 'الحساب موقوف' : 'Account suspended';
    }
});
const blockedBody = computed(() => {
    const ar = locale.value === 'ar';
    switch (accountAccess.reason) {
        case 'company_inactive': return ar ? 'تم إغلاق حساب التاجر هذا. يرجى التواصل مع فريق دعم مثقال.' : 'This merchant account is closed. Contact MITHQAL support.';
        case 'company_unavailable': return ar ? 'حساب التاجر هذا غير متاح. يرجى التواصل مع فريق دعم مثقال.' : 'This merchant account is not available. Contact MITHQAL support.';
        default: return ar ? 'يرجى التواصل مع فريق الدعم لاستعادة الوصول إلى حساب التاجر.' : 'Contact support to restore access to your merchant account.';
    }
});
watch(() => accountAccess.suspended, suspended => {
    if (suspended) document.title = blockedTitle.value;
});
</script>

<template>
    <main v-if="accountAccess.suspended" class="flex min-h-screen items-center justify-center bg-slate-50 p-6" role="alert">
        <section class="w-full max-w-lg rounded-2xl border border-amber-200 bg-white p-8 text-center shadow-sm">
            <h1 class="text-2xl font-semibold text-slate-900">{{ blockedTitle }}</h1>
            <p class="mt-4 text-slate-600">{{ blockedBody }}</p>
            <a href="/login" class="mt-6 inline-block rounded-lg bg-slate-900 px-5 py-3 font-medium text-white">{{ locale === 'ar' ? 'العودة إلى تسجيل الدخول' : 'Return to sign in' }}</a>
        </section>
    </main>
    <RouterView v-else />
</template>
