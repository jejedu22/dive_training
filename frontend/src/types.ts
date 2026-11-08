export type Role = 'ROLE_REFERENT' | 'ROLE_ADMIN'
export interface User { id: number; email: string; name: string; roles: Role[]; referent: boolean; admin: boolean; active: boolean }
export interface Health { api: string; database: string; hasUsers: boolean | null; jwtKeys: string }
export interface Lane { id: number; name: string; maxDepth: number | null; poolId: number }
export interface Pool { id: number; name: string; lanes: Lane[] }
export interface Diver { id: number; firstName: string; lastName: string; level: string | null }
export interface Section { id: number; name: string; targetLevel: string | null; season: string | null; color: string; instructors: User[]; divers: Diver[] }
export type Status = 'PENDING' | 'CONFIRMED' | 'REJECTED'
export interface Reservation {
  id: number; sessionId: number; title: string; section: string; color: string; lane: Lane; pool: string
  start: string; end: string; status: Status; requestedBy: string | null; validatedBy: string | null; planned: boolean
}
export interface Participant { diver: Diver; usesTank: boolean }
export interface DiveSession {
  id: number; title: string; sectionId: number; section: string; color: string; start: string; end: string
  reservations: Reservation[]; participants?: Participant[]; tankCount?: number
}
export interface ClubSlot {
  id: number; label: string | null; weekdays: number[]; startTime: string; endTime: string
  poolId: number | null; pool: string | null; validFrom: string | null; validUntil: string | null; description: string
  laneIds: number[]; availableLaneIds: number[]; allocations: Allocation[]
}
export interface Allocation { laneId: number; sectionId: number; section: string; color: string }
export interface SlotOccurrence {
  slotId: number; label: string; poolId: number | null; pool: string | null; start: string; end: string
  laneIds: number[]; allocations: Allocation[]
}
export interface OccupancyReservation {
  id: number; sessionId: number; title: string; sectionId: number; section: string; color: string; status: Status; start: string; end: string; mine: boolean
}
export interface OccupancyLane {
  laneId: number; lane: string; poolId: number; pool: string
  allocation: { sectionId: number; section: string; color: string; mine: boolean } | null
  reservations: OccupancyReservation[]
}
export interface Occurrence { slotId: number; label: string; start: string; end: string; lanes: OccupancyLane[] }
export interface Occupancy { mySectionIds: number[]; occurrences: Occurrence[] }
export interface Instructor { id: number; name: string }
export interface CalendarData {
  slots: SlotOccurrence[]
  resources: { id: string; title: string; pool: string; poolId: number }[]
  events: { id: string; resourceId: string; title: string; start: string; end: string; status: Status; sessionId: number; sectionId: number; section: string; color: string }[]
}
