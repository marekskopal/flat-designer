import {HttpClient} from '@angular/common/http';
import {computed, inject, Injectable, signal} from '@angular/core';
import {User} from '@app/models/user';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

/** Profile of the signed-in user, loaded on demand and cached until sign-out. */
@Injectable({providedIn: 'root'})
export class CurrentUserService {
    private readonly http = inject(HttpClient);

    public readonly currentUser = signal<User | null>(null);
    public readonly isAdmin = computed(() => this.currentUser()?.role === 'Admin');

    public async load(): Promise<User> {
        const user = await firstValueFrom(this.http.get<User>(`${environment.apiUrl}/current-user`));
        this.currentUser.set(user);
        return user;
    }

    /** The cached profile, or a fresh load when there is none yet. */
    public async get(): Promise<User> {
        return this.currentUser() ?? this.load();
    }

    public async updateName(name: string): Promise<User> {
        const user = await firstValueFrom(this.http.patch<User>(`${environment.apiUrl}/current-user`, {name}));
        this.currentUser.set(user);
        return user;
    }

    public clear(): void {
        this.currentUser.set(null);
    }
}
