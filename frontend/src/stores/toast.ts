import { defineStore } from 'pinia'

export type ToastKind = 'ok' | 'error' | 'info'
interface Toast { id: number; text: string; kind: ToastKind }
let next = 1

/** Notifications éphémères en bas d'écran (succès, erreurs). */
export const useToast = defineStore('toast', {
  state: () => ({ items: [] as Toast[] }),
  actions: {
    show(text: string, kind: ToastKind = 'ok') {
      const id = next++
      this.items.push({ id, text, kind })
      setTimeout(() => this.dismiss(id), kind === 'error' ? 6000 : 3500)
    },
    ok(text: string) { this.show(text, 'ok') },
    error(e: unknown) { this.show(e instanceof Error ? e.message : String(e), 'error') },
    dismiss(id: number) { this.items = this.items.filter((t) => t.id !== id) },
  },
})
