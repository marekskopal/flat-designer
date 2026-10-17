// Mirrors FlatDesigner\Dto\ChangeDto, EditResultDto and the item kind catalogue.

export interface Change {
    id: number;
    operation: 'Create' | 'Update' | 'Delete';
    elementType: 'Item' | 'Wall' | 'Opening' | 'Room';
    elementId: number;
    floorId: number | null;
    summary: string;
    actorType: 'Human' | 'Agent';
    /** The person, or for an agent the MCP client's name. */
    actorName: string;
    revision: number;
    undone: boolean;
    createdAt: string;
}

/** Answer to an edit, undo or redo. */
export interface EditResult {
    revision: number;
    /** Null when the edit changed nothing. */
    change: Change | null;
    /** The element's state as in the scene; null when it was deleted. */
    element: Record<string, unknown> | null;
}

export interface ItemKindInfo {
    kind: string;
    description: string;
    width: number;
    depth: number;
    height: number;
    z: number;
    params: string;
}
