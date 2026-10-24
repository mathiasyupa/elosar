const ECUADOR_TIME_ZONE = 'America/Guayaquil';

/** Returns a calendar-date input value in Elosar's operating time zone. */
export function ecuadorDateInputValue(date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: ECUADOR_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);

  const values = Object.fromEntries(
    parts
      .filter((part) => part.type !== 'literal')
      .map((part) => [part.type, part.value]),
  );

  return `${values.year}-${values.month}-${values.day}`;
}
