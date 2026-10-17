import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, effect, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {PasswordHint, passwordValidator} from '@app/core/validators/password.validator';
import {AlertService} from '@app/services/alert.service';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

@Component({
    selector: 'fd-account',
    imports: [ReactiveFormsModule],
    templateUrl: './account.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AccountComponent {
    protected readonly currentUser = inject(CurrentUserService);
    private readonly auth = inject(AuthenticationService);
    private readonly alerts = inject(AlertService);
    private readonly fb = inject(FormBuilder).nonNullable;

    protected readonly passwordHint = PasswordHint;
    protected readonly savingProfile = signal(false);
    protected readonly savingPassword = signal(false);
    protected readonly passwordError = signal<string | null>(null);

    protected readonly profileForm = this.fb.group({
        name: ['', Validators.required],
    });

    protected readonly passwordForm = this.fb.group({
        currentPassword: ['', Validators.required],
        newPassword: ['', [Validators.required, passwordValidator]],
    });

    public constructor() {
        effect(() => {
            const user = this.currentUser.currentUser();
            if (user !== null && this.profileForm.pristine) {
                this.profileForm.setValue({name: user.name});
            }
        });
    }

    protected async saveProfile(): Promise<void> {
        if (this.profileForm.invalid) {
            return;
        }
        this.savingProfile.set(true);
        try {
            await this.currentUser.updateName(this.profileForm.getRawValue().name);
            this.profileForm.markAsPristine();
            this.alerts.success('Profile saved.');
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.savingProfile.set(false);
        }
    }

    protected async changePassword(): Promise<void> {
        if (this.passwordForm.invalid) {
            return;
        }
        this.savingPassword.set(true);
        this.passwordError.set(null);
        try {
            const {currentPassword, newPassword} = this.passwordForm.getRawValue();
            await this.auth.changePassword(currentPassword, newPassword);
            this.passwordForm.reset();
            this.alerts.success('Password changed. Other sessions have been signed out.');
        } catch (e: unknown) {
            this.passwordError.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Could not change the password.');
        } finally {
            this.savingPassword.set(false);
        }
    }
}
