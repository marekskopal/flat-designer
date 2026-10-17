import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {Flat, Scene} from '@app/models/scene';
import {environment} from '@environments/environment';
import {Observable} from 'rxjs';

@Injectable({providedIn: 'root'})
export class SceneApiService {
    private readonly http = inject(HttpClient);

    public getFlats(): Observable<Flat[]> {
        return this.http.get<Flat[]>(`${environment.apiUrl}/flats`);
    }

    public getScene(flatId: number): Observable<Scene> {
        return this.http.get<Scene>(`${environment.apiUrl}/flats/${flatId}/scene`);
    }
}
