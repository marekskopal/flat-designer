// Mirrors FlatDesigner\Dto\ChangeDto, EditResultDto and the item kind catalogue.

export interface Change {
    id: number;
    operation: 'Create' | 'Update' | 'Delete';
    elementType: 'Item' | 'Wall' | 'Opening' | 'Room' | 'Group' | 'VariantSet';
    elementId: number;
    floorId: number | null;
    summary: string;
    actorType: 'Human' | 'Agent';
    /** The person, or for an agent the MCP client's name. */
    actorName: string;
    revision: number;
    undone: boolean;
    createdAt: string;
    /** Shared by the changes of one operation on several elements (e.g. moving a group); the first names the operation. */
    batch: string | null;
}

/** One change of a batch with its element's state afterwards (null: deleted). */
export interface BatchEntry {
    change: Change;
    element: Record<string, unknown> | null;
}

/** Answer to an edit, undo or redo. */
export interface EditResult {
    revision: number;
    /** Null when the edit changed nothing. */
    change: Change | null;
    /** The element's state as in the scene; null when it was deleted. For a batch: the group it is about. */
    element: Record<string, unknown> | null;
    /** Every change of an operation on several elements, in the order applied (`change` is the first). */
    batch?: BatchEntry[];
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
