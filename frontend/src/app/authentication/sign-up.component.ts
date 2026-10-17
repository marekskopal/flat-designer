import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {PasswordHint, passwordValidator} from '@app/core/validators/password.validator';
import {AuthenticationService} from '@app/services/authentication.service';

@Component({
    selector: 'fd-sign-up',
    imports: [ReactiveFormsModule, RouterLink],
    templateUrl: './sign-up.component.html',
    styleUrl: './auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignUpComponent {
    private readonly auth = inject(AuthenticationService);
    private readonly router = inject(Router);

    protected readonly passwordHint = PasswordHint;
    protected readonly saving = signal(false);
    protected readonly error = signal<string | null>(null);
    protected readonly form = inject(FormBuilder).nonNullable.group({
        name: ['', Validators.required],
        email: ['', [Validators.required, Validators.email]],
        password: ['', [Validators.required, passwordValidator]],
    });

    protected async onSubmit(): Promise<void> {
        if (this.form.invalid) {
            return;
        }
        this.saving.set(true);
        this.error.set(null);
        try {
            const {name, email, password} = this.form.getRawValue();
            await this.auth.signUp(email, password, name);
            await this.router.navigateByUrl('/');
        } catch (e: unknown) {
            // A 401 from the follow-up login means the email was registered already (with another password).
            this.error.set(
                e instanceof HttpErrorResponse && e.status === 401
                    ? 'Could not sign you in. If you already have an account, sign in instead.'
                    : e instanceof HttpErrorResponse
                        ? errorMessage(e)
                        : 'Sign-up failed.',
            );
        } finally {
            this.saving.set(false);
        }
    }
}
