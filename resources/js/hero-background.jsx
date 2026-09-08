import React from 'react';
import { createRoot } from 'react-dom/client';
import AeroShards from './components/AeroShards';

const base = {
    backgroundColor: '#171717',
    shardColor: '#6D5107',
    accentColor: '#cd9501',
    placement: 'full',
    material: 'pearl',
    detail: 'fine',
    effect: 'none',
    flow: 'stream',
    rippleIntensity: 1,
    holdToGather: false,
    scale: 1,
    spread: 1,
    depth: 1,
    speed: 0.3,
    spin: 1,
    interaction: 'attract',
    density: 1,
    shardSize: 1.1,
    stretch: 1,
    turbulence: 1,
    glow: 1.5,
    edgeSoftness: 2,
    bloom: 2,
    grain: 0.05,
    chromaticAberration: 0.0075,
    transitionDuration: 1,
    interactionRadius: 1.5,
    interactionStrength: 0.3,
    paused: false,
};

const variants = {
    'hero-background': base,
    'cta-background': {
        ...base,
        backgroundColor: '#171717',
        placement: 'left',
        material: 'satin',
        flow: 'stream',
        density: 0.9,
        shardSize: 0.9,
        speed: 1,
        spin: 0.6,
        glow: 0.8,
        bloom: 0.35,
    },
};

for (const [id, props] of Object.entries(variants)) {
    const container = document.getElementById(id);
    if (!container) continue;

    createRoot(container).render(
        <div style={{ width: '100%', height: '100%', position: 'relative' }}>
            <AeroShards {...props} />
        </div>
    );
}
