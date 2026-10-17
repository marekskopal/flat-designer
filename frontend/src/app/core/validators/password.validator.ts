import {AbstractControl, ValidationErrors} from '@angular/forms';

/** Same rule as the backend's `PasswordValidator`. */
export const PasswordHint = 'At least 8 characters with an uppercase letter, a lowercase letter and a digit.';

export function passwordValidator(control: AbstractControl<string>): ValidationErrors | null {
    const value = control.value;
    const valid = value.length >= 8 && /[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value);
    return valid ? null : {password: true};
}
