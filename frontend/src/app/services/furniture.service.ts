import {HttpClient, HttpContext} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {SkipErrorToast} from '@app/core/interceptors/error.interceptor';
import {FurnitureDefinition, FurnitureDefinitionBody, FurnitureDefinitionInput, FurnitureScope, FurnitureValidation} from '@app/models/furniture';
import {Material} from '@app/models/scene';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

const definitionsUrl = `${environment.apiUrl}/furniture-definitions`;

/** Furniture definitions (global, my library, a project's) and the material catalogue their slots pick from. */
@Injectable({providedIn: 'root'})
export class FurnitureService {
    private readonly http = inject(HttpClient);

    /** Global ones and my library; with a project, what its items may use (global, the project's, its owner's library). */
    public async list(projectId?: number): Promise<FurnitureDefinition[]> {
        const params: Record<string, number> = projectId === undefined ? {} : {projectId};
        return firstValueFrom(this.http.get<FurnitureDefinition[]>(definitionsUrl, {params}));
    }

    /** With `itemCount`. */
    public async get(definitionId: number): Promise<FurnitureDefinition> {
        return firstValueFrom(this.http.get<FurnitureDefinition>(`${definitionsUrl}/${definitionId}`));
    }

    public async create(input: FurnitureDefinitionInput): Promise<FurnitureDefinition> {
        return firstValueFrom(this.http.post<FurnitureDefinition>(definitionsUrl, input));
    }

    /** Name, description and definition; the scope stays. Errors are left to the caller (they name the path). */
    public async update(definitionId: number, input: Omit<FurnitureDefinitionInput, 'scope' | 'projectId'>): Promise<FurnitureDefinition> {
        return firstValueFrom(
            this.http.put<FurnitureDefinition>(`${definitionsUrl}/${definitionId}`, input, {context: new HttpContext().set(SkipErrorToast, true)}),
        );
    }

    public async delete(definitionId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${definitionsUrl}/${definitionId}`));
    }

    public async copy(definitionId: number, scope: FurnitureScope, projectId?: number): Promise<FurnitureDefinition> {
        return firstValueFrom(this.http.post<FurnitureDefinition>(`${definitionsUrl}/${definitionId}/copy`, {scope, projectId}));
    }

    /** The server's full check (part types, material codes, …) without saving. */
    public async validate(definition: FurnitureDefinitionBody): Promise<FurnitureValidation> {
        return firstValueFrom(this.http.post<FurnitureValidation>(`${definitionsUrl}/validate`, {definition}));
    }

    public async materials(): Promise<Material[]> {
        return firstValueFrom(this.http.get<Material[]>(`${environment.apiUrl}/materials`));
    }
}
