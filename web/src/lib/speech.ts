/**
 * The browser's own speech-to-text (Web Speech API), for "Escuchar con Elo".
 * Chrome and Edge ship it; where it is missing the button simply does not
 * show. TypeScript's DOM lib does not declare it, so the slice we use is
 * typed here.
 */

interface SpeechAlternative {
  transcript: string;
}

interface SpeechResult {
  readonly isFinal: boolean;
  readonly length: number;
  [index: number]: SpeechAlternative;
}

interface SpeechResultEvent {
  readonly resultIndex: number;
  readonly results: { readonly length: number; [index: number]: SpeechResult };
}

interface SpeechErrorEvent {
  readonly error: string;
}

export interface SpeechRecognitionLike {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((event: SpeechResultEvent) => void) | null;
  onerror: ((event: SpeechErrorEvent) => void) | null;
  onend: (() => void) | null;
  start(): void;
  stop(): void;
  abort(): void;
}

type SpeechCtor = new () => SpeechRecognitionLike;

function speechCtor(): SpeechCtor | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as { SpeechRecognition?: SpeechCtor; webkitSpeechRecognition?: SpeechCtor };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export function isSpeechSupported(): boolean {
  return speechCtor() !== null;
}

/** Why listening stopped on its own. */
export type DictationError = 'denied' | 'no-mic' | 'network' | 'other';

export interface DictationHandlers {
  /** Everything heard so far: settled text, and the words still being recognised. */
  onText: (finalText: string, interim: string) => void;
  onError: (error: DictationError) => void;
}

/**
 * Starts listening in Ecuadorian Spanish until `stop()` is called. Chrome
 * ends a session after a pause; while the doctor has not pressed stop, it is
 * started again so a long consulta is heard whole.
 */
export function startDictation({ onText, onError }: DictationHandlers): { stop: () => Promise<string>; cancel: () => void } {
  const Ctor = speechCtor();
  if (!Ctor) throw new Error('Speech recognition is not supported in this browser.');

  let finalText = '';
  let heard = '';
  let lastInterim = '';
  let wanted = true;
  let settle: ((text: string) => void) | null = null;
  let recognition: SpeechRecognitionLike;

  const open = () => {
    recognition = new Ctor();
    recognition.lang = 'es-EC';
    recognition.continuous = true;
    recognition.interimResults = true;

    // Each session numbers its results from zero: what was settled in an
    // earlier session lives in `finalText`, this one's in `sessionFinal`.
    let sessionFinal = '';
    recognition.onresult = (event) => {
      let interim = '';
      sessionFinal = '';
      for (let i = 0; i < event.results.length; i++) {
        const result = event.results[i];
        const text = result[0]?.transcript ?? '';
        if (result.isFinal) sessionFinal += text;
        else interim += text;
      }
      heard = join(finalText, sessionFinal);
      lastInterim = interim.trim();
      onText(heard, lastInterim);
    };
    recognition.onerror = (event) => {
      // "no-speech" and "aborted" are pauses, not failures: onend restarts.
      if (event.error === 'no-speech' || event.error === 'aborted') return;
      wanted = false;
      onError(
        event.error === 'not-allowed' || event.error === 'service-not-allowed'
          ? 'denied'
          : event.error === 'audio-capture'
            ? 'no-mic'
            : event.error === 'network'
              ? 'network'
              : 'other',
      );
    };
    recognition.onend = () => {
      finalText = join(finalText, sessionFinal);
      sessionFinal = '';
      if (wanted) {
        open();
        return;
      }
      settle?.(finalText);
    };
    recognition.start();
  };

  open();

  return {
    /**
     * Resolves with everything heard once the browser hands over its last
     * words. If it never does, what was still being recognised counts too.
     */
    stop: () =>
      new Promise<string>((resolve) => {
        wanted = false;
        const fallback = window.setTimeout(() => {
          settle = null;
          resolve(join(heard, lastInterim));
        }, 1500);
        settle = (text) => {
          window.clearTimeout(fallback);
          settle = null;
          resolve(text);
        };
        recognition.stop();
      }),
    cancel: () => {
      wanted = false;
      settle = null;
      recognition.abort();
    },
  };
}

function join(a: string, b: string): string {
  return [a.trim(), b.trim()].filter(Boolean).join(' ');
}

/** A 10-digit run is probably a cédula (or a phone): it never travels to the AI. */
export function scrubIdentifiers(text: string): string {
  return text.replace(/\d(?:[\s-]?\d){9,}/g, '[número]');
}
