/** Live update of a project, pushed by the server after a committed change (see ProjectUpdatePublisher). */
export type ProjectUpdate = ProjectChange | DefinitionChange;

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
