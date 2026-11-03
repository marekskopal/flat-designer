import {NgOptimizedImage} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, inject, signal} from '@angular/core';
import {AbstractControl, FormBuilder, ReactiveFormsModule, ValidationErrors, Validators} from '@angular/forms';
import {ActivatedRoute, Router, RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {PasswordHint, passwordValidator} from '@app/core/validators/password.validator';
import {AuthenticationService} from '@app/services/authentication.service';

/** The page a password reset mail links to (`/reset-password?token=…`); setting the password signs the user in. */
@Component({
    selector: 'fd-reset-password',
    imports: [NgOptimizedImage, ReactiveFormsModule, RouterLink],
    templateUrl: './reset-password.component.html',
    styleUrl: './auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResetPasswordComponent {
    private readonly auth = inject(AuthenticationService);
    private readonly router = inject(Router);

    protected readonly token = inject(ActivatedRoute).snapshot.queryParamMap.get('token');
    protected readonly passwordHint = PasswordHint;
    protected readonly saving = signal(false);
    protected readonly error = signal<string | null>(null);
    protected readonly form = inject(FormBuilder).nonNullable.group(
        {
            password: ['', [Validators.required, passwordValidator]],
            confirmPassword: ['', Validators.required],
        },
        {validators: [matchPasswords]},
    );

    protected async onSubmit(): Promise<void> {
        if (this.form.invalid || this.token === null) {
            return;
        }
        this.saving.set(true);
        this.error.set(null);
        try {
            await this.auth.confirmPasswordReset(this.token, this.form.getRawValue().password);
            await this.router.navigateByUrl('/');
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Could not set the password.');
        } finally {
            this.saving.set(false);
        }
    }
}

function matchPasswords(control: AbstractControl): ValidationErrors | null {
    const password: unknown = control.get('password')?.value;
    const confirm: unknown = control.get('confirmPassword')?.value;
    return password !== '' && confirm !== '' && password !== confirm ? {passwordMismatch: true} : null;
}
