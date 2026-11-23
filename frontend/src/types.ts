export type InstructorLevel = 'E1' | 'E2' | 'E3' | 'E4'
export type Role = 'ROLE_REFERENT' | 'ROLE_ADMIN'
export interface Preferences { homePage: 'planning' | 'sections'; planningView: 'week' | 'month'; onlyMine: boolean }
export interface User {
  id: number; email: string; name: string; phone: string | null; avatarColor: string
  roles: Role[]; referent: boolean; admin: boolean; active: boolean
  /** Niveau d'encadrement FFESSM (E1 à E4), tuteur de stage initiateur, délivrance du N1 (président). */
  level: InstructorLevel | null; tsi: boolean; canIssue: boolean
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
export interface ShareLink { id: number; token: string; label: string | null; createdBy: string | null; createdAt: string; expiresAt: string | null; hasCode: boolean }

// ---------- Suivi des stagiaires (MFT FFESSM)
export type SkillStatus = 'NT' | 'ECA' | 'A' | 'V'
export type Place = 'pool' | 'pit' | 'natural'
export interface MftCompetency { key: string; title: string; group: string | null; quals: string[]; skills: Record<string, string>; final: boolean; theory: boolean; natural: boolean }
export interface MftModule {
  key: string; title: string; minSessions: number; minValidated: number; distinct: boolean; exemptable: string | null; help?: string
  phases: Record<string, Record<string, string>>; requirements: Record<string, string>
}
export interface MftCurriculum {
  type: 'diver' | 'teaching'; modules?: MftModule[]; tutor?: string; levelsTaught?: string[]
  code: string; name: string; subtitle: string; version: string; qualifications: Record<string, string>
  validateLevel: InstructorLevel; issueLevel: InstructorLevel; presidentMayIssue: boolean; naturalOnly: boolean; delayMonths: number
  prerequisites: string[]; notes: string[]; competencies: MftCompetency[]
}
export interface Mft { curricula: MftCurriculum[]; levels: InstructorLevel[]; places: Record<Place, string>; statuses: Record<string, string> }
export interface EvaluationEntry {
  id: number; skill: string; status: SkillStatus; date: string; place: Place; site: string | null; depth: number | null
  comment: string | null; instructor: string; instructorId: number | null
}
export interface EnrolmentSummary {
  id: number; curriculum: string; name: string; version: string; startedAt: string; status: 'active' | 'done' | 'abandoned'
  progress: { validated: number; started: number; total: number }
  firstValidatedAt: string | null; deadline: string | null; issued: Record<string, { date: string; by: string }>
}
export interface Enrolment extends EnrolmentSummary {
  prerequisites: { label: string; done: boolean }[]
  competencies: { key: string; status: SkillStatus }[]
  /** Par élément : statut, dernière évaluation, raison qui empêche « Validé » (null = possible) selon le lieu. */
  skills: Record<string, { status: SkillStatus; last: EvaluationEntry | null; validate: { natural: string | null; pool: string | null } }>
  evaluations: EvaluationEntry[]
  qualifications: { code: string; label: string; issued: { date: string; by: string } | null; cannotIssue: string | null }[]
  // Formation Initiateur
  modules?: ModuleState[]; dossier?: { stageInitial?: { date: string; ctr: string | null } }; canTutor?: boolean
}
export type Stamp = { date: string; by: string }
export interface TeachingSessionEntry {
  id: number; date: string; level: string | null; theme: string | null; items: Record<string, 'A' | 'ECA' | 'NT'>
  comment: string | null; instructor: string; instructorId: number | null; complete: boolean; validated: boolean
}
export interface ModuleState {
  key: string; validated: Stamp | null; exempted: Stamp | null; requirements: Record<string, Stamp>
  counts: { sessions: number; complete: number; validated: number; themes: number; levels: number }
  cannotValidate: string | null; sessions: TeachingSessionEntry[]
}
export interface SectionRef { id: number; name: string; color: string }
export interface TraineeSummary extends Diver { licence: string | null; archived: boolean; sections: SectionRef[]; enrolments: EnrolmentSummary[] }
export interface Trainee extends Diver { licence: string | null; archived: boolean; sections: SectionRef[]; canEvaluate: boolean; enrolments: Enrolment[] }
