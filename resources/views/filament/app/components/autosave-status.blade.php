@if (method_exists($this, 'autosave'))
    @php($hasPersistedRecord = method_exists($this, 'getRecord') && $this->getRecord()?->exists)
    <div
        class="memoria-private-banner flex-wrap"
        aria-live="polite"
        data-autosave-guard
        x-data="{
            dirty: false,
            online: navigator.onLine,
            issue: null,
            saving: false,
            persistedWhenLoaded: @js($hasPersistedRecord),
            form: null,
            requestCleanup: null,
            markDirty: null,
            handleOffline: null,
            handleOnline: null,
            handleBeforeUnload: null,
            handleNavigate: null,
            init() {
                this.form = this.$el.closest('form')
                this.markDirty = () => {
                    this.dirty = true
                    if (! this.online) this.issue = 'offline'
                }
                this.handleOffline = () => {
                    this.online = false
                    if (this.dirty) this.issue = 'offline'
                }
                this.handleOnline = () => {
                    this.online = true
                    if (this.dirty) this.issue = 'retry'
                }
                this.handleBeforeUnload = (event) => {
                    if (! this.dirty) return
                    event.preventDefault()
                    event.returnValue = ''
                }
                this.handleNavigate = (event) => {
                    if (! this.dirty) return
                    if (! this.persistedWhenLoaded && $wire.isCreating) {
                        this.dirty = false
                        return
                    }
                    if (window.confirm(@js(__('Some changes have not been saved. Leave this memory anyway?')))) {
                        this.dirty = false
                        return
                    }
                    event.preventDefault()
                }

                this.form?.addEventListener('input', this.markDirty, true)
                this.form?.addEventListener('change', this.markDirty, true)
                window.addEventListener('offline', this.handleOffline)
                window.addEventListener('online', this.handleOnline)
                window.addEventListener('beforeunload', this.handleBeforeUnload)
                document.addEventListener('livewire:navigate', this.handleNavigate)

                this.requestCleanup = window.Livewire?.hook('request', ({ succeed, fail }) => {
                    if (this.dirty) this.saving = true
                    succeed(() => { this.saving = false })
                    fail(({ status, preventDefault }) => {
                        this.saving = false
                        if (! this.dirty) return
                        this.issue = status === 419 ? 'expired' : 'network'
                        if (status === 419) preventDefault()
                    })
                })
            },
            destroy() {
                this.form?.removeEventListener('input', this.markDirty, true)
                this.form?.removeEventListener('change', this.markDirty, true)
                window.removeEventListener('offline', this.handleOffline)
                window.removeEventListener('online', this.handleOnline)
                window.removeEventListener('beforeunload', this.handleBeforeUnload)
                document.removeEventListener('livewire:navigate', this.handleNavigate)
                if (typeof this.requestCleanup === 'function') this.requestCleanup()
            },
            retry() {
                if (! this.online) return
                this.issue = null
                $wire.autosave()
            },
            reload() {
                window.location.reload()
            },
        }"
        x-bind:data-dirty="dirty ? 'true' : 'false'"
        x-on:entry-autosave-succeeded.window="dirty = false; issue = null"
        x-on:entry-autosave-failed.window="dirty = true; issue = $event.detail.kind"
    >
        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="5" y="10" width="14" height="10" rx="2" />
            <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
        </svg>

        <span x-show="! dirty">{{ $hasPersistedRecord ? __('Saved privately · Only me') : __('Not saved yet · This draft will be private') }}</span>
        <span x-cloak x-show="dirty && saving && online && ! issue">{{ __('Saving privately…') }}</span>
        <span x-cloak x-show="dirty && ! saving && online && ! issue">{{ __('Changes waiting to save privately…') }}</span>
        <span x-cloak x-show="dirty && issue === 'offline'">{{ __('You are offline · Changes are still in this window') }}</span>
        <span x-cloak x-show="dirty && issue === 'retry'">{{ __('Back online · Your changes still need to be saved') }}</span>
        <span x-cloak x-show="dirty && issue === 'network'">{{ __('Couldn’t reach Memoria · Your changes are still in this window') }}</span>
        <span x-cloak x-show="dirty && issue === 'server'">{{ __('Couldn’t save · Your changes are still in this window') }}</span>
        <span x-cloak x-show="dirty && issue === 'validation'">{{ __('Not saved · Check the highlighted details and try again.') }}</span>
        <span x-cloak x-show="dirty && issue === 'conflict'">{{ __('Not saved · A newer version exists. Copy your changes before reloading.') }}</span>
        <span x-cloak x-show="dirty && issue === 'expired'">{{ __('Your session ended · Copy unsaved writing, then reload to continue.') }}</span>

        <button
            type="button"
            class="ms-auto rounded-md border border-current/25 px-2 py-1 text-xs font-semibold hover:bg-black/5 dark:hover:bg-white/5"
            x-cloak
            x-show="dirty && online && ['retry', 'network', 'server'].includes(issue)"
            x-on:click="retry"
        >
            {{ __('Retry save') }}
        </button>
        <button
            type="button"
            class="ms-auto rounded-md border border-current/25 px-2 py-1 text-xs font-semibold hover:bg-black/5 dark:hover:bg-white/5"
            x-cloak
            x-show="dirty && ['conflict', 'expired'].includes(issue)"
            x-on:click="reload"
        >
            {{ __('Reload') }}
        </button>
    </div>
@else
    <div class="memoria-private-banner" aria-live="polite">
        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="5" y="10" width="14" height="10" rx="2" />
            <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
        </svg>
        <span>{{ __('Not saved yet · This draft will be private') }}</span>
    </div>
@endif
