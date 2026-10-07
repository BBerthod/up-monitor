<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import BackLink from '@/Components/BackLink.vue'
import GlassCard from '@/Components/GlassCard.vue'
import CopyButton from '@/Components/CopyButton.vue'

defineOptions({ layout: AppLayout })

interface RepoContext {
    url: string
    branch: string | null
    app_id: string | null
    app_name: string | null
}

const props = defineProps<{
    kind: 'incident' | 'insight'
    title: string
    prompt: string
    site: string
    repo: RepoContext | null
    [key: string]: unknown
}>()

// Large copy button with its own feedback state (CopyButton is icon-only; we want
// a labelled button here, so we replicate the same clipboard logic).
const copied = ref(false)

const copyPrompt = async () => {
    try {
        await navigator.clipboard.writeText(props.prompt)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch {
        // Fallback: silently ignore
    }
}

const backHref = props.kind === 'incident'
    ? route('incidents.index')
    : route('dashboard')

// One-shot terminal command: clone (when the repo is known) and hand the
// prompt straight to a claude session — copy, paste, done.
const shellQuote = (value: string) => "'" + value.replace(/'/g, "'\\''") + "'"

const terminalCommand = () => {
    const claudeCall = 'claude ' + shellQuote(props.prompt)

    if (!props.repo) return claudeCall

    const branch = props.repo.branch ? ` --branch ${props.repo.branch}` : ''

    return `d=$(mktemp -d) && git clone${branch} --depth 1 ${props.repo.url} "$d" && cd "$d" && ${claudeCall}`
}

const copiedCommand = ref(false)

const copyCommand = async () => {
    try {
        await navigator.clipboard.writeText(terminalCommand())
        copiedCommand.value = true
        setTimeout(() => { copiedCommand.value = false }, 2000)
    } catch {
        // Fallback: silently ignore
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6">
        <PageHeader
            :title="title"
            description="Copy this prompt into Claude Code to investigate and fix it."
        />

        <BackLink :href="backHref" :label="kind === 'incident' ? 'Back to Incidents' : 'Back to Dashboard'" />

        <GlassCard>
            <!-- Meta row: site badge + repo -->
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Site badge -->
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-mono font-medium bg-transparent text-zinc-400 border border-zinc-800">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                        {{ site }}
                    </span>

                    <!-- Repo -->
                    <template v-if="repo">
                        <div class="flex items-center gap-1.5 px-2.5 py-1 rounded bg-transparent border border-zinc-800 text-xs font-mono text-zinc-400">
                            <svg class="w-3 h-3 flex-shrink-0 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
                            </svg>
                            <span class="truncate max-w-[240px]">{{ repo.url }}</span>
                            <span v-if="repo.branch" class="text-zinc-600">·</span>
                            <span v-if="repo.branch" class="text-zinc-500">{{ repo.branch }}</span>
                            <CopyButton :text="repo.url" size="sm" />
                        </div>
                    </template>
                    <template v-else>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs bg-amber-500/8 text-amber-400/70 border border-amber-500/15">
                            <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            Repository not resolved — specify it manually in the prompt.
                        </span>
                    </template>
                </div>

                <!-- Primary copy button -->
                <button
                    type="button"
                    @click="copyPrompt"
                    :class="[
                        'inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/50',
                        copied
                            ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                            : 'bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/20 hover:border-emerald-500/30'
                    ]"
                    :aria-label="copied ? 'Prompt copied!' : 'Copy prompt to clipboard'"
                >
                    <Transition
                        enter-active-class="transition ease-out duration-150"
                        enter-from-class="opacity-0 scale-75"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-100"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-75"
                        mode="out-in"
                    >
                        <svg v-if="copied" key="check" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg v-else key="copy" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </Transition>
                    {{ copied ? 'Copied!' : 'Copy prompt' }}
                </button>
            </div>

            <!-- One-shot terminal command -->
            <div class="mb-5 flex items-center gap-3 rounded-lg bg-black/30 border border-white/5 px-4 py-3">
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-1">Terminal one-liner</p>
                    <p class="font-mono text-xs text-zinc-400 truncate">{{ terminalCommand() }}</p>
                </div>
                <button
                    type="button"
                    @click="copyCommand"
                    :class="[
                        'shrink-0 inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        copiedCommand
                            ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                            : 'bg-white/5 hover:bg-white/10 text-zinc-300 border border-white/10',
                    ]"
                >
                    {{ copiedCommand ? 'Copied!' : 'Copy command' }}
                </button>
            </div>

            <!-- Prompt block -->
            <pre class="font-mono text-sm text-zinc-300 bg-black/30 rounded-lg p-4 whitespace-pre-wrap break-words border border-white/5 max-h-[60vh] overflow-y-auto leading-relaxed scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10">{{ prompt }}</pre>

            <!-- Footer hint -->
            <p class="mt-4 text-xs text-zinc-600">
                Paste this prompt into a <span class="font-mono text-zinc-500">claude</span> session opened at the repository root. Claude Code will read the relevant files and propose fixes.
            </p>
        </GlassCard>
    </div>
</template>
