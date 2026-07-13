import type { VariantProps } from 'class-variance-authority';
import { cva } from 'class-variance-authority';

export { default as Button } from './Button.vue';
export { default as ButtonLink } from './ButtonLink.vue';

/** Bevel recipe (Untitled UI): inset highlight border masked to fade downward; press inverts it. */
const bevel =
    'shadow-xs-skeuomorphic before:absolute before:inset-px before:rounded-[calc(var(--radius-md)-1px)] before:border before:border-white/20 before:[mask-image:linear-gradient(to_bottom,black,transparent)] active:brightness-95 active:shadow-[0px_0px_0px_1px_rgba(0,0,0,0.18)_inset,0px_2px_4px_0px_rgba(0,0,0,0.35)_inset] active:before:border-white/10 active:before:[mask-image:linear-gradient(to_top,black,transparent)]';

export const buttonVariants = cva(
    "relative inline-flex items-center justify-center gap-[7px] whitespace-nowrap rounded-md text-[13.5px] font-semibold cursor-pointer transition-all disabled:pointer-events-none disabled:opacity-45 [&_svg]:pointer-events-none [&_svg]:stroke-[1.8] [&_svg:not([class*='size-'])]:size-[15px] [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
    {
        variants: {
            variant: {
                default: `bg-primary text-primary-foreground hover:bg-primary-hi ${bevel}`,
                destructive: `bg-destructive text-white hover:bg-[#d31b49] focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 ${bevel}`,
                secondary: `bg-secondary text-secondary-foreground hover:bg-[#35363c] ${bevel} before:border-white/10 active:before:border-white/5`,
                outline: 'border border-line-2 bg-transparent hover:border-white/25',
                ghost: 'text-muted-foreground hover:bg-muted hover:text-foreground',
                link: 'text-primary underline-offset-4 hover:text-primary-hi hover:underline',
            },
            size: {
                default: 'px-4 py-[9px] has-[>svg]:px-3.5',
                sm: 'px-[13px] py-1.5 text-[12.5px]',
                lg: 'px-6 py-3 text-[14.5px]',
                icon: 'size-[38px]',
                'icon-sm': 'size-[30px]',
                'icon-lg': 'size-11',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);
export type ButtonVariants = VariantProps<typeof buttonVariants>;
