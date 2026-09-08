import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import LaserFlow from './components/LaserFlow.jsx';

const mount = document.getElementById('cta-laserflow');

if (mount) {
    createRoot(mount).render(
        <StrictMode>
            <LaserFlow
                color="#FFB900"
                wispDensity={1}
                flowSpeed={0.35}
                verticalSizing={2}
                horizontalSizing={0.5}
                fogIntensity={0.45}
                fogScale={0.3}
                wispSpeed={15}
                wispIntensity={5}
                flowStrength={0.25}
                decay={1.1}
                horizontalBeamOffset={0.05}
                verticalBeamOffset={-0.5}
                backgroundColor="#171717"
            />
        </StrictMode>
    );
}
