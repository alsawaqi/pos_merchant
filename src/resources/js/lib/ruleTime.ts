import { onMounted, onUnmounted, ref } from 'vue';

/** Oman has a fixed UTC+4 offset and no daylight-saving changes. */
const OMAN_OFFSET_MS = 4 * 60 * 60 * 1000;

export function omanDateTimeInput(instant: string | null | undefined): string {
    if (!instant) return '';
    const milliseconds = Date.parse(instant);
    if (!Number.isFinite(milliseconds)) return '';
    return new Date(milliseconds + OMAN_OFFSET_MS).toISOString().slice(0, 16);
}

/** Send an explicit instant, independently of the browser/computer timezone. */
export function omanDateTimePayload(value: string): string | null {
    return value ? `${value}:00+04:00` : null;
}

type RuleWindow = {
    status: string;
    validity_start: string | null;
    validity_end: string | null;
};

export function ruleWindowStatus(rule: RuleWindow, now: number): 'paused' | 'scheduled' | 'active' | 'expired' {
    if (rule.status === 'paused') return 'paused';
    if (rule.status === 'expired' || (rule.validity_end && now > Date.parse(rule.validity_end))) return 'expired';
    if (rule.validity_start && now < Date.parse(rule.validity_start)) return 'scheduled';
    return 'active';
}

/** Keep a visible list accurate when it crosses a rule's start/end instant. */
export function useRuleClock() {
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | undefined;
    onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
    onUnmounted(() => { if (timer !== undefined) clearInterval(timer); });
    return now;
}
