/**
 * Universe settings page: (1) the three expedition resource sliders that always add up to their maximum,
 * (2) the flight time preview below the distance settings. Data from the JSON island #config-uni-data
 * ({preview: {speedFactor, shipSpeed, gameSpeed, fleetSpeedFactor, minDuration}, text: {...messages}}).
 */
import { pageData } from '../../app/core.js';

const data = pageData('config-uni-data');

/** keep the sliders balanced: moving one distributes the difference over the following ones */
function balancedSliders(ids) {
  const sliders = ids.map((id) => document.getElementById(id));
  if (sliders.some((slider) => !slider)) return;
  const total = Number.parseInt(sliders[0].max, 10);
  const label = (index, value) => {
    document.getElementById(`${ids[index]}Value`).innerText = `${100 * (value / total)} %`;
  };
  sliders.forEach((slider, index) => {
    label(index, Number.parseInt(slider.value, 10));
    slider.addEventListener('input', () => {
      label(index, Number.parseInt(slider.value, 10));
      const sum = sliders.reduce((acc, other) => acc + Number.parseInt(other.value, 10), 0);
      let toChange = Math.trunc(total - sum);
      let position = (index + 1) % sliders.length;
      while (toChange !== 0) {
        const next = Math.trunc(Number.parseInt(sliders[position].value, 10) + toChange);
        if (next < 0) {
          toChange = next;
          sliders[position].value = 0;
          label(position, 0);
          position = (position + 1) % sliders.length;
        } else {
          toChange = 0;
          sliders[position].value = next;
          label(position, next);
        }
      }
    });
  });
}

/** flight time of a ship from 1:1:1 to the typed coordinates under the (unsaved) form values */
function flightTimePreview() {
  const settings = data.preview;
  const t = data.text ?? {};
  const targetInput = document.getElementById('flight-time-preview-target');
  const resultNode = document.getElementById('flight-time-preview-result');
  const distanceNode = document.getElementById('flight-time-preview-distance');
  if (!settings || !targetInput || !resultNode || !distanceNode) return;

  const observedFields = ['max_galaxy', 'max_system', 'max_planets', 'uni_type', 'galaxy_type', 'galaxy_distance_factor',
    'system_distance_factor', 'system_distance', 'planet_distance_factor', 'planet_distance'];

  const fieldValue = (name) => {
    const field = document.getElementsByName(name)[0];
    return field ? Number.parseInt(field.value, 10) : NaN;
  };
  const formatNumber = (value) => String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const pad = (value) => String(value).padStart(2, '0');

  const formatDuration = (seconds) => {
    const whole = Math.floor(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole - hours * 3600) / 60);
    return `${hours}:${pad(minutes)}:${pad(whole - hours * 3600 - minutes * 60)} h`;
  };

  const parseTarget = () => {
    const match = targetInput.value.trim().match(/^(\d+):(\d+):(\d+)$/);
    return match ? { galaxy: Number(match[1]), system: Number(match[2]), planet: Number(match[3]) } : null;
  };

  /** error text for an unusable target, '' when fine */
  const validateTarget = (target) => {
    const maxGalaxy = fieldValue('max_galaxy');
    const maxSystem = fieldValue('max_system');
    const maxPlanets = fieldValue('max_planets');
    if (!target) return t.format;
    if (![maxGalaxy, maxSystem, maxPlanets].every(Number.isFinite)) return t.maxMissing;
    if (target.galaxy < 1 || target.system < 1 || target.planet < 1) return t.minOne;
    if (target.galaxy > maxGalaxy) return t.galaxyOver;
    if (target.system > maxSystem) return t.systemOver;
    if (target.planet > maxPlanets) return t.planetOver;
    return '';
  };

  /** Number of steps between two positions on a ring of `max` positions (circular universe/galaxy type: the shorter way round counts). */
  const ringDistance = (current, target, max) => {
    const diff = Math.abs(current - target);
    return diff < Math.ceil(max / 2) ? diff : Math.floor(max / 2) - (diff - Math.ceil(max / 2));
  };

  /**
   * Distance from 1:1:1 to the target using the current form values, same rules as the PHP FleetMath::getTargetDistance():
   * galaxy, system or planet step with the universe/galaxy type (0 linear, 1 circular, 2 constant), distance factors and
   * base distances; 5 for the same planet.
   */
  const distanceTo = (target) => {
    const uniType = fieldValue('uni_type');
    const galaxyType = fieldValue('galaxy_type');
    const maxGalaxy = fieldValue('max_galaxy');
    const maxSystem = fieldValue('max_system');
    const galaxyFactor = fieldValue('galaxy_distance_factor');
    const systemFactor = fieldValue('system_distance_factor');
    const systemDistance = fieldValue('system_distance');
    const planetFactor = fieldValue('planet_distance_factor');
    const planetDistance = fieldValue('planet_distance');

    if (target.galaxy !== 1) {
      if (uniType === 2) return galaxyFactor;
      if (uniType === 1) return ringDistance(1, target.galaxy, maxGalaxy) * galaxyFactor;
      return Math.abs(1 - target.galaxy) * galaxyFactor;
    }
    if (target.system !== 1) {
      if (galaxyType === 2) return systemFactor + systemDistance;
      if (galaxyType === 1) return ringDistance(1, target.system, maxSystem) * systemFactor + systemDistance;
      return Math.abs(1 - target.system) * systemFactor + systemDistance;
    }
    if (target.planet !== 1) return Math.abs(1 - target.planet) * planetFactor + planetDistance;
    return 5;
  };

  /** One-way flight time in seconds for a distance (mirror of PHP FleetMath::getMissionDuration() at full speed), at least `minDuration`. */
  const durationFor = (distance) => {
    const duration = ((3500 / (settings.speedFactor * 0.1) * Math.sqrt(distance * 10 / settings.shipSpeed) + 10) / settings.gameSpeed) * settings.fleetSpeedFactor;
    return Math.floor(Math.max(duration, settings.minDuration));
  };

  /** Recomputes the preview for the typed target coordinates; shows a validation message instead when they are out of range. */
  const update = () => {
    const target = parseTarget();
    const error = validateTarget(target);
    if (error !== '') {
      resultNode.textContent = error;
      distanceNode.textContent = '';
      return;
    }
    const distance = distanceTo(target);
    const duration = durationFor(distance);
    if (!Number.isFinite(distance) || !Number.isFinite(duration)) {
      resultNode.textContent = t.distMissing;
      distanceNode.textContent = '';
      return;
    }
    resultNode.textContent = `${t.time}: ${formatDuration(duration)}`;
    distanceNode.textContent = `${t.distance}: ${formatNumber(distance)} | ${t.shipSpeed}: ${formatNumber(settings.shipSpeed)} | ${t.fleetSpeed}: 1`;
  };

  targetInput.addEventListener('input', update);
  for (const name of observedFields) {
    const field = document.getElementsByName(name)[0];
    field?.addEventListener('input', update);
    field?.addEventListener('change', update);
  }
  update();
}

balancedSliders(['expo_ress_met_chance', 'expo_ress_crys_chance', 'expo_ress_deut_chance']);
flightTimePreview();
