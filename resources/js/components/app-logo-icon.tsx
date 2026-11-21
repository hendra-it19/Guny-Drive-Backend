import type { SVGAttributes } from 'react';

export default function AppLogoIcon({
    className,
    ...props
}: SVGAttributes<SVGElement>) {
    const sanitizedClassName = className
        ?.replace(/\bfill-current\b/g, '')
        .trim();

    return (
        <svg
            {...props}
            className={sanitizedClassName}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.15"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path
                d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.5 4.5 0 0 1-.5 9.5Z"
                fill="currentColor"
                fillOpacity="0.16"
                stroke="none"
            />
            <path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.5 4.5 0 0 1-.5 9.5Z" />
        </svg>
    );
}
