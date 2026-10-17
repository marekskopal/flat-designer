/** Live update of a project, pushed by the server after a committed change (see ProjectUpdatePublisher). */
export interface ProjectUpdate {
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
