import React from 'react';
import { createRoot } from 'react-dom/client';
import AeroShards from './components/AeroShards';

const container = document.getElementById('hero-background');

if (container) {
    const root = createRoot(container);
    root.render(
        <div style={{ width: '100%', height: '100%', position: 'relative' }}>
            <AeroShards
                backgroundColor="#171717"
                shardColor="#6D5107"
                accentColor="#cd9501"
                placement="full"
                material="pearl"
                detail="fine"
                effect="none"
                flow="stream"
                rippleIntensity={1}
                holdToGather={false}
                scale={1}
                spread={1}
                depth={1}
                speed={1}
                spin={1}
                interaction="repel"
                density={1.5}
                shardSize={1.1}
                stretch={1}
                turbulence={1}
                glow={1}
                edgeSoftness={2}
                bloom={0.5}
                grain={0.05}
                chromaticAberration={0.0075}
                transitionDuration={1}
                interactionRadius={1.5}
                interactionStrength={0.5}
                paused={false}
            />
        </div>
    );
}
