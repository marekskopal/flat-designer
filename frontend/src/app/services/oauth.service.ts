import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {McpClient} from '@app/models/mcp-client';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

export interface AuthorizeRequest {
    clientId: string;
    redirectUri: string;
    codeChallenge: string;
    codeChallengeMethod: string;
    state: string;
    resource?: string;
}

export interface AuthorizeResult {
    code: string;
    redirectUri: string;
    state: string;
}

/** Consent for MCP clients (OAuth authorization step) and the user's connected clients. */
@Injectable({providedIn: 'root'})
export class OAuthService {
    private readonly http = inject(HttpClient);

    public async clientName(clientId: string): Promise<string> {
        const info = await firstValueFrom(
            this.http.get<{clientName: string}>(`${environment.apiUrl}/oauth/client-info`, {params: {client_id: clientId}}),
        );
        return info.clientName;
    }

    public async authorize(request: AuthorizeRequest): Promise<AuthorizeResult> {
        return firstValueFrom(this.http.post<AuthorizeResult>(`${environment.apiUrl}/oauth/authorize`, request));
    }

    public async listClients(): Promise<McpClient[]> {
        return firstValueFrom(this.http.get<McpClient[]>(`${environment.apiUrl}/current-user/mcp-clients`));
    }

    public async revokeClient(clientId: string): Promise<void> {
        await firstValueFrom(this.http.post<void>(`${environment.apiUrl}/current-user/mcp-clients/${clientId}/revoke`, {}));
    }
}
