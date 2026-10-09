<script setup>
// The settings a space can have its own of, or the app-wide defaults. Empty means "as the
// default", which shows as the placeholder, so nobody has to know what it is to keep it.
const props = defineProps({
    modelValue: { type: Object, required: true },
    defaults: { type: Object, required: true },
    // On a space: also the rules and automatic patches, which have their own place on Setup.
    perSpace: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value === '' ? null : value });
const efforts = ['low', 'medium', 'high', 'xhigh', 'max'];
const field = 'mt-1 w-full rounded-md border border-line bg-page px-2 py-1.5 text-sm placeholder:text-muted';
</script>

<template>
    <datalist id="claude-models">
        <option value="claude-opus-5" /><option value="claude-sonnet-5" /><option value="claude-haiku-4-5" /><option value="claude-fable-5-1" />
    </datalist>
    <div class="grid gap-4 sm:grid-cols-3">
        <p class="text-sm font-medium sm:col-span-3">Analyses and questions</p>
        <label class="text-sm">Model
            <input :value="modelValue.model ?? ''" list="claude-models" :placeholder="defaults.model" :class="field" class="font-mono" @input="set('model', $event.target.value)" />
        </label>
        <label class="text-sm">Effort
            <select :value="modelValue.effort ?? ''" :class="field" @change="set('effort', $event.target.value)">
                <option value="">as the default ({{ defaults.effort }})</option>
                <option v-for="effort in efforts" :key="effort" :value="effort">{{ effort }}</option>
            </select>
        </label>
        <label class="text-sm">Budget per turn ($)
            <input :value="modelValue.turn_budget_usd ?? ''" type="number" step="0.5" min="0.1" :placeholder="defaults.turn_budget_usd" :class="field" @input="set('turn_budget_usd', $event.target.value)" />
        </label>

        <p class="text-sm font-medium sm:col-span-3">Quick scans</p>
        <label class="text-sm">Model
            <input :value="modelValue.quick_model ?? ''" list="claude-models" :placeholder="defaults.quick_model" :class="field" class="font-mono" @input="set('quick_model', $event.target.value)" />
        </label>
        <label class="text-sm">Effort
            <select :value="modelValue.quick_effort ?? ''" :class="field" @change="set('quick_effort', $event.target.value)">
                <option value="">as the default ({{ defaults.quick_effort }})</option>
                <option v-for="effort in efforts" :key="effort" :value="effort">{{ effort }}</option>
            </select>
        </label>
        <label class="text-sm">Budget per scan ($)
            <input :value="modelValue.quick_budget_usd ?? ''" type="number" step="0.05" min="0.05" :placeholder="defaults.quick_budget_usd" :class="field" @input="set('quick_budget_usd', $event.target.value)" />
        </label>

        <p class="text-sm font-medium sm:col-span-3">Budget</p>
        <label class="text-sm sm:col-span-3">
            Monthly budget ($) <span class="text-muted">{{ perSpace ? 'for all work on this space' : 'per space, unless a space sets its own' }}; empty: none</span>
            <input :value="modelValue.monthly_budget_usd ?? ''" type="number" step="10" min="1" :placeholder="defaults.monthly_budget_usd ?? 'none'" :class="field" class="max-w-48" @input="set('monthly_budget_usd', $event.target.value)" />
            <span class="mt-1 block text-xs text-muted">At 80% the ticket pages warn; at 100% no new agent work starts on it this month.</span>
        </label>

        <template v-if="perSpace">
            <label class="text-sm sm:col-span-3">Automatic patches
                <select :value="modelValue.auto_patch ?? ''" :class="field" class="max-w-64" @change="set('auto_patch', $event.target.value === '' ? null : $event.target.value === '1')">
                    <option value="">as the default ({{ defaults.auto_patch ? 'on' : 'off' }})</option>
                    <option value="1">on: a code fix gets its patch</option>
                    <option value="0">off: only when asked</option>
                </select>
            </label>
            <label class="text-sm sm:col-span-3">Reply rules <span class="text-muted">(empty: the ones on Setup)</span>
                <textarea :value="modelValue.reply_rules ?? ''" rows="5" :placeholder="defaults.reply_rules" :class="field" @input="set('reply_rules', $event.target.value)"></textarea>
            </label>
            <label class="text-sm sm:col-span-3">Answer rules <span class="text-muted">(empty: the ones on Setup)</span>
                <textarea :value="modelValue.answer_rules ?? ''" rows="4" :placeholder="defaults.answer_rules" :class="field" @input="set('answer_rules', $event.target.value)"></textarea>
            </label>
        </template>
    </div>
</template>
