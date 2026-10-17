/** An MCP client (e.g. Claude Code) the user authorized to edit their projects. */
export interface McpClient {
    clientId: string;
    clientName: string;
    connectedAt: string;
    lastTokenAt: string;
    /** Holds a usable token; false once disconnected or expired. */
    active: boolean;
}
