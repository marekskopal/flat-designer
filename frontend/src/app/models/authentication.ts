export interface Authentication {
    accessToken: string;
    refreshToken: string;
    userId: number;
}

/** What the sign-in page offers besides email and password (`GET /api/authentication/options`). */
export interface AuthenticationOptions {
    /** The web client id of Sign in with Google, null when it is off. */
    googleClientId: string | null;
    /** Whether the server sends mail: password reset and email verification links. */
    mail: boolean;
}
