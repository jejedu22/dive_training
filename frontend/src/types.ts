export type Role = 'ROLE_REFERENT' | 'ROLE_ADMIN'
export interface User { id: number; email: string; name: string; roles: Role[]; referent: boolean; admin: boolean; active: boolean }
export interface Health { api: string; database: string; hasUsers: boolean | null; jwtKeys: string }
export interface Lane { id: number; name: string; maxDepth: number | null; poolId: number }
export interface Pool { id: number; name: string; lanes: Lane[] }
export interface Diver { id: number; firstName: string; lastName: string; level: string | null }
export interface Section { id: number; name: string; targetLevel: string | null; season: string | null; instructors: User[]; divers: Diver[] }
export type Status = 'PENDING' | 'CONFIRMED' | 'REJECTED'
export interface Reservation {
  id: number; sessionId: number; title: string; section: string; lane: Lane; pool: string
  start: string; end: string; status: Status; requestedBy: string | null; validatedBy: string | null
}
export interface Participant { diver: Diver; usesTank: boolean }
export interface DiveSession {
  id: number; title: string; sectionId: number; section: string; start: string; end: string
  reservations: Reservation[]; participants?: Participant[]; tankCount?: number
}
export interface ClubSlot {
  id: number; label: string | null; weekdays: number[]; startTime: string; endTime: string
  poolId: number | null; pool: string | null; validFrom: string | null; validUntil: string | null; description: string
}
export interface SlotOccurrence { slotId: number; label: string; poolId: number | null; pool: string | null; start: string; end: string }
export interface CalendarData {
  slots: SlotOccurrence[]
  resources: { id: string; title: string; pool: string; poolId: number }[]
  events: { id: string; resourceId: string; title: string; start: string; end: string; status: Status; sessionId: number }[]
}
