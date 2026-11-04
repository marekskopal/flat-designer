/**
 * Whether the browser can draw the 3D view: three.js needs WebGL 2. A browser may have it switched off (hardware
 * acceleration off, a blocked GPU or driver) or lose the context later (the GPU reset); the pages then show a message
 * instead of a blank view.
 */
export const webglAvailable = (): boolean => {
    try {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('webgl2');
        if (context === null) {
            return false;
        }
        // Free the probe's context at once: browsers allow only a few at a time.
        context.getExtension('WEBGL_lose_context')?.loseContext();
        return true;
    } catch {
        return false;
    }
};

/** Why the 3D view is not shown: no WebGL at all, or its context was lost while the page was open. */
export type WebglProblem = 'unsupported' | 'lost';
