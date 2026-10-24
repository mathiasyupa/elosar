import type { ButtonHTMLAttributes } from 'react';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost';
  size?: 'md' | 'sm';
}

const VARIANTS: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary: 'primary',
  secondary: '',
  ghost: 'ghost',
};

export function Button({ variant = 'primary', size = 'md', className = '', ...props }: ButtonProps) {
  const classes = ['btn', VARIANTS[variant], size === 'sm' ? 'sm' : '', className]
    .filter(Boolean)
    .join(' ');
  return <button className={classes} {...props} />;
}
