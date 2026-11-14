// The authenticated download replaces this with the operator-configured origin.
// Loading the raw source directory must not grant access to an arbitrary site.
globalThis.YANJI_INSTANCE = Object.freeze({ origin: null, id: null, version: null });
