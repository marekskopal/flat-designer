/** Live update of a project, pushed by the server after a committed change (see ProjectUpdatePublisher). */
export type ProjectUpdate = ProjectChange | DefinitionChange | MaterialChange | DetailsChange | PricesChange | CommentsChange;

/** A committed change of the project (an edit, undo, restore, a new floor…). */
export interface ProjectChange {
    type: 'projectChanged';
    projectId: number;
    /** The project's revision after the change. */
    revision: number;
    /** Set when the update comes from a logged element change. */
    floorId: number | null;
    summary: string | null;
    undone: boolean;
    actorType: 'Human' | 'Agent' | null;
    actorName: string | null;
    /** Browser tab that made the change (`X-Origin-Client-Id`), null for agents. */
    originClientId: string | null;
}

/** A furniture definition its items are drawn from was edited: the items look different, the revision is the same. */
export interface DefinitionChange {
    type: 'definitionChanged';
    projectId: number;
    definitionId: number;
    /** Browser tab that saved the definition. */
    originClientId: string | null;
}

/** The project's name, address or description changed: not floor content, so the revision is the same. */
export interface DetailsChange {
    type: 'detailsChanged';
    projectId: number;
    /** Browser tab that changed them (null for agents). */
    originClientId: string | null;
}

/** A material of the global catalogue was changed (by an admin): everything using it looks different, the revision is the same. */
export interface MaterialChange {
    type: 'materialChanged';
    projectId: number;
    materialId: number;
    /** Browser tab that changed it (null for agents). */
    originClientId: string | null;
}

/** The project's price list (quantities and cost) was saved: not floor content, so the revision is the same. */
export interface PricesChange {
    type: 'pricesChanged';
    projectId: number;
    /** Browser tab that saved it (null for agents). */
    originClientId: string | null;
}

/** A review comment was added, changed or deleted: not floor content, so the revision is the same. */
export interface CommentsChange {
    type: 'commentsChanged';
    projectId: number;
    /** Browser tab that changed it (null for agents and guests). */
    originClientId: string | null;
}
