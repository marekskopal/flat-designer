import {AbstractControl, ValidationErrors} from '@angular/forms';


/** Same rule as the backend's `PasswordValidator` (described by `auth.passwordHint`). */
export function passwordValidator(control: AbstractControl<string>): ValidationErrors | null {
    const value = control.value;
    const valid = value.length >= 8 && /[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value);
    return valid ? null : {password: true};
}
