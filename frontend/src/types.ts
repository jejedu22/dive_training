export type Role = 'ROLE_REFERENT' | 'ROLE_ADMIN'
export interface Preferences { homePage: 'planning' | 'sections'; planningView: 'week' | 'month'; onlyMine: boolean }
export interface User {
  id: number; email: string; name: string; phone: string | null; avatarColor: string
  roles: Role[]; referent: boolean; admin: boolean; active: boolean
  createdAt: string | null; lastLoginAt: string | null
  preferences?: Preferences                                    // seulement pour l'utilisateur connecté
  sections?: { id: number; name: string; color: string }[]     // seulement dans la gestion des comptes
}
export interface Health { api: string; database: string; hasUsers: boolean | null; jwtKeys: string }

export interface Lane { id: number; name: string; maxDepth: number | null; position: number; poolId: number }
export interface Pool { id: number; name: string; lanes: Lane[] }
export interface Diver { id: number; firstName: string; lastName: string; level: string | null }
export interface Section {
  id: number; name: string; targetLevel: string | null; season: string | null; color: string; usesTanks: boolean
  instructors: { id: number; name: string }[]; divers: Diver[]
}
export interface Instructor { id: number; name: string }

/** Créneau du club, son plan habituel (ligne → section) et ses exceptions. */
export interface ClubSlot {
  id: number; label: string | null; weekdays: number[]; startTime: string; endTime: string
  poolId: number | null; pool: string | null; validFrom: string | null; validUntil: string | null; description: string
  laneIds: number[]; availableLaneIds: number[]; allocations: Allocation[]; overrides: Override[]
}
export interface Allocation { laneId: number; sectionId: number; section: string; color: string }
/** Exception au plan habituel : règle mensuelle (n-ième jour, -1 = dernier) ou date précise. */
export interface Override {
  id: number; slotId: number; label: string | null; kind: 'date' | 'monthly'; date: string | null; weekday: number | null; nth: number | null
  validFrom: string | null; validUntil: string | null; cancelled: boolean; rule: string
  allocations: { laneId: number; sectionId: number | null; section: string | null; color: string | null }[]
}
export interface ExceptionRef { id: number; label: string | null; rule: string; cancelled: boolean; punctual?: boolean }

/** Planning : une occurrence de créneau à une date, avec la section de chaque ligne ouverte. */
export interface PlanningLane {
  laneId: number; lane: string; poolId: number; pool: string
  /** Sections qui occupent la ligne (vide = libre ; 2 = ligne partagée). */
  sections: { id: number; name: string; color: string; mine: boolean; usesTanks: boolean }[]
  exception: boolean
}
export interface PlanningOccurrence {
  slotId: number; label: string; date: string; start: string; end: string; cancelled: boolean
  lanes: PlanningLane[]; exceptions: ExceptionRef[]
  tanks?: Record<string, number> // sectionId -> nombre de bouteilles (absent sur un lien de partage)
}
export interface Planning { mySectionIds: number[]; occurrences: PlanningOccurrence[] }
export interface PresenceList {
  section: { id: number; name: string; color: string }; date: string; slotId: number
  divers: { diver: Diver; usesTank: boolean }[]; tankCount: number
}
export interface ShareLink { id: number; token: string; label: string | null; createdBy: string | null; createdAt: string; expiresAt: string | null }
