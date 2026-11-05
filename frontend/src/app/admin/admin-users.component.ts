import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {passwordValidator} from '@app/core/validators/password.validator';
import {User, UserRole} from '@app/models/user';
import {AdminUserService} from '@app/services/admin-user.service';
import {AlertService} from '@app/services/alert.service';
import {CurrentUserService} from '@app/services/current-user.service';
import {LanguageService} from '@app/services/language.service';
import {DateTextPipe} from '@app/shared/i18n/date-text.pipe';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

/** Admin overview of all accounts: add one, change the role, set a password, delete. */
@Component({
    selector: 'fd-admin-users',
    imports: [DateTextPipe, ReactiveFormsModule, TranslatePipe],
    templateUrl: './admin-users.component.html',
    styleUrl: './admin-users.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdminUsersComponent implements OnInit {
    private readonly translate = inject(TranslateService);
    protected readonly currentUser = inject(CurrentUserService);
    private readonly adminUsers = inject(AdminUserService);
    private readonly alerts = inject(AlertService);
    private readonly language = inject(LanguageService);

    protected readonly roles: UserRole[] = ['User', 'Admin'];
    protected readonly users = signal<User[]>([]);
    protected readonly loading = signal(true);
    protected readonly adding = signal(false);
    protected readonly saving = signal(false);
    /** The user whose password is being set. */
    protected readonly passwordFor = signal<number | null>(null);
    private readonly forms = inject(FormBuilder).nonNullable;
    protected readonly addForm = this.forms.group({
        name: ['', Validators.required],
        email: ['', [Validators.required, Validators.email]],
        password: ['', [Validators.required, passwordValidator]],
        role: ['User' as UserRole],
    });
    protected readonly passwordForm = this.forms.group({password: ['', [Validators.required, passwordValidator]]});

    public async ngOnInit(): Promise<void> {
        try {
            this.users.set(await this.adminUsers.list());
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
        }
    }

    protected async add(): Promise<void> {
        if (this.addForm.invalid) {
            return;
        }
        this.saving.set(true);
        try {
            const user = await this.adminUsers.create({...this.addForm.getRawValue(), locale: this.language.current()});
            this.users.update((all) => [...all, user]);
            this.alerts.success(this.translate.instant('admin.added', {name: user.name}));
            this.cancelAdd();
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.saving.set(false);
        }
    }

    protected cancelAdd(): void {
        this.addForm.reset();
        this.adding.set(false);
    }

    protected editPassword(user: User | null): void {
        this.passwordForm.reset();
        this.passwordFor.set(user?.id ?? null);
    }

    protected async setPassword(user: User): Promise<void> {
        if (this.passwordForm.invalid) {
            return;
        }
        this.saving.set(true);
        try {
            this.replace(await this.adminUsers.update(user.id, {password: this.passwordForm.getRawValue().password}));
            this.alerts.success(this.translate.instant('admin.passwordSet', {name: user.name}));
            this.editPassword(null);
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.saving.set(false);
        }
    }

    protected async changeRole(user: User, event: Event): Promise<void> {
        const select = event.target as HTMLSelectElement;
        try {
            this.replace(await this.adminUsers.update(user.id, {role: select.value as UserRole}));
            this.alerts.success(this.translate.instant(select.value === 'Admin' ? 'admin.nowAdmin' : 'admin.nowUser', {name: user.name}));
        } catch {
            select.value = user.role;
        }
    }

    protected async delete(user: User): Promise<void> {
        if (!confirm(this.translate.instant('admin.confirmDelete', {name: user.name, email: user.email}))) {
            return;
        }
        try {
            await this.adminUsers.delete(user.id);
            this.users.update((all) => all.filter((u) => u.id !== user.id));
            this.alerts.success(this.translate.instant('admin.deleted', {name: user.name}));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private replace(updated: User): void {
        this.users.update((all) => all.map((u) => (u.id === updated.id ? updated : u)));
    }
}
