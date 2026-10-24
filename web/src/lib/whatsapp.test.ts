import { describe, expect, it } from 'vitest';
import {
  anniversaryMessage,
  apptReminderMessage,
  birthdayMessage,
  followUpMessage,
  reactivationMessage,
  welcomeMessage,
} from './whatsapp';

// Emoji outside the BMP arrive as "�" in WhatsApp Desktop through a wa.me link.
const hasEmoji = (text: string) => /\p{Extended_Pictographic}/u.test(text);

describe('WhatsApp messages', () => {
  it('greets by first name in normal case, even when the registry has capitals', () => {
    expect(birthdayMessage('KAROLAY PAOLA CRIOLLO')).toContain('¡Feliz cumpleaños, Karolay!');
    expect(birthdayMessage('maría josé')).toContain('María');
  });

  it('wishes the birthday warmly and never in the third person', () => {
    const message = birthdayMessage('Claudia Vera');

    expect(message).toContain('Todo nuestro equipo te desea');
    expect(message).toContain('Gracias por confiar en nosotros');
  });

  it('carries no emoji, which WhatsApp Desktop breaks through a link', () => {
    const messages = [
      birthdayMessage('Ana'),
      anniversaryMessage('Ana', 2),
      welcomeMessage('Ana'),
      followUpMessage('Ana', 'control de presión'),
      reactivationMessage('Ana'),
      apptReminderMessage({ name: 'Ana', dateLabel: 'lunes 20', timeLabel: '09:00', doctorName: 'Dr. Salazar' }),
    ];

    expect(messages.filter(hasEmoji)).toEqual([]);
  });
});
