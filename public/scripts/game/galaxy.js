import { post, pretty_number, pageData } from '../app/core.js';
import { registerActions } from '../app/actions.js';

/**
 * Galaxy page: mission buttons (spy, recycle, ...), the missile form, system navigation (buttons and
 * keyboard) and the local galaxy data store (sync / export / import / reset).
 * Server data (current system, labels) comes from the data island #galaxy-data (template
 * page.galaxy.default.html.twig); the controls are data-action markup (see app/actions.js).
 */
const galaxyData = pageData('galaxy-data');
const galakey = galaxyData.galakey;
// position -> planet data (null: empty or destroyed), as stored in the local galaxy data
const systemdata = {};
for (const { position, data } of galaxyData.systemRows) systemdata[position] = data;
systemdata.timepoint = Date.now();
const maxFleetSetting = galaxyData.maxFleetSetting;
const planetlng = galaxyData.planetLng;
const moonshortlng = galaxyData.moonShortLng;
const confirmlng = galaxyData.confirmLng;

/** sends a fleet mission (spy, recycle, ...) of the galaxy view to the server; asks first when the server wants a confirmation */
function doit(missionID, planetID) {
  post("game?page=fleetAjax&ajax=1&mission=" + missionID + "&planetID=" + planetID, {}, 'json').then(async function (data) {
    if (data.code === 602) {
      //602 = needs confirmation
      if (!(await window.Dialog.confirm(data.mess))) {
        return;
      }
      post("game?page=fleetAjax&ajax=1&mission=" + missionID + "&planetID=" + planetID + "&confirm=1", {}, 'json').then(function (data) {
        handleDoIt(data)
      });
      return;
    }

    handleDoIt(data)
  });
}

/** Shows the result of a mission request: updates free slots and ship counts, appends the server message to the status list (oldest dropped at `maxFleetSetting`), green for code 600, red otherwise. */
function handleDoIt(data) {
  document.getElementById('slots').textContent = data.slots;
  if (typeof data.ships !== "undefined") {
    Object.entries(data.ships).forEach(function ([elementID, value]) {
      const cell = document.getElementById('elementID' + elementID);
      if (cell) cell.textContent = pretty_number(value);
    });
  }

  let statustable = document.getElementById('fleetstatusrow');
  let messages = statustable.querySelectorAll(':scope > div');
  if (messages.length == maxFleetSetting) {
    messages[0].remove();
  }
  let element = document.createElement('div');
  element.className = "galaxy-grid-item centered span6 " + (data.code === 600 ? "success" : "error");
  element.textContent = data.mess;
  statustable.hidden = false;
  statustable.append(element);

}

/** Fills the interplanetary missile form with the target (galaxy, system, position, planet/moon type) and flight time (seconds), shows it and focuses the amount field. Returns false (cancels the link). */
function showMissileForm(galaxy, system, planetPos, duration, type) {
  const form = document.querySelector('form[action="?page=fleetMissile"]');
  if (!form) return false;
  form.querySelector('input[name="galaxy"]').value = galaxy;
  form.querySelector('input[name="system"]').value = system;
  form.querySelector('input[name="planet"]').value = planetPos;
  form.querySelector('input[name="type"]').value = type;
  form.querySelector('input[name="duration"]').value = duration;
  const flyTime = document.getElementById('irakFlyTime');
  if (flyTime) flyTime.textContent = duration;
  const table = document.getElementById('missileForm');
  if (table) table.hidden = false;
  const sendInput = form.querySelector('input[name="SendMI"]');
  if (sendInput) sendInput.focus();
  return false;
}

/** Answer of the missile pre-check (`mission=10`): code 600 opens the form, anything else is shown as an alert. */
function handleMissileAjax(data, galaxy, system, planetPos, duration, type) {
  if (data.code !== 600) {
    window.Dialog.alert(data.mess);
    return;
  }
  showMissileForm(galaxy, system, planetPos, duration, type);
}

/** Pre-checks a missile attack on the planet via `fleetAjax` (mission 10); code 602 asks the player to confirm first (outlaw rules), then opens the form on success. Returns false (cancels the link). */
function openMissileForm(planetID, galaxy, system, planetPos, duration, type) {
  post("game?page=fleetAjax&ajax=1&mission=10&planetID=" + planetID, {}, 'json').then(async function (data) {
    if (data.code === 602) {
      //602 = needs confirmation (become/remain outlaw vs strong player)
      if (!(await window.Dialog.confirm(data.mess))) {
        return;
      }
      post("game?page=fleetAjax&ajax=1&mission=10&planetID=" + planetID + "&confirm=1", {}, 'json').then(function (data) {
        handleMissileAjax(data, galaxy, system, planetPos, duration, type);
      });
      return;
    }
    handleMissileAjax(data, galaxy, system, planetPos, duration, type);
  });
  return false;
}

/** Navigates the galaxy view: the clicked direction (`systemLeft`, `systemRight`, `galaxyLeft`, `galaxyRight`) is sent as the name of the hidden field and the form submitted. */
function galaxy_submit(value) {
  document.getElementById('auto').setAttribute('name', value);
  document.getElementById('galaxy_form').submit();
}

/** "uniN" of the current URL path (default "uni1"); prefix of the local storage keys. */
function parseUni() {
  return window.location.pathname.match('uni?.')?.[0] || 'uni1'
}

/**
 * Per-universe galaxy data store on localStorage (keys "<uniN>_<name>", JSON values).
 * The key format is unchanged so galaxy data saved by earlier versions still loads.
 * Storage errors (quota, disabled storage, corrupt JSON) fall back to the default / are ignored.
 */
const galaxyStore = {
  get(key, defaultValue) {
    try {
      const raw = localStorage.getItem(`${parseUni()}_${key}`);
      return raw === null ? defaultValue : JSON.parse(raw);
    } catch (e) {
      return defaultValue;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(`${parseUni()}_${key}`, JSON.stringify(value));
    } catch (e) {
      console.warn('galaxy data could not be saved', e);
    }
  },
};

/** Local galaxy data store: `universe` ("g:s" -> planets by position, with a timepoint), `playerids` and `allyids` (id -> name + timepoint). Entries with the newer timepoint win when data is merged. */
const universe = galaxyStore.get("universe", {})
const playerids = galaxyStore.get("playerids", {})
const allyids = galaxyStore.get("allyids", {})


/** Hooks the sync / export / import / reset buttons of the galaxy tools and adds the stored positions to the player tooltips. */
function addlisteners() {
  document.getElementById("gala_sync").onclick = () => {
    syncsys();
    document.getElementById("gala_sync").value = "Synced";
  }

  document.getElementById('gala_upload').addEventListener('change', onChange_import);

  document.getElementById("gala_exportjson").onclick = export_json
  document.getElementById("gala_exportcsv").onclick = export_csv
  document.getElementById("gala_reset").onclick = reset
  showplayerinfos();
}

onPageInit(function () {
  addlisteners();
});

/** Reads the chosen JSON file (as written by export_json) for onReaderLoad_import(). */
function onChange_import(event) {
  const reader = new FileReader();
  reader.onload = onReaderLoad_import;
  reader.readAsText(event.target.files[0]);
}

/** Merges an exported galaxy file ([universe, playerids, allyids]) into the local store; per entry the newer timepoint wins, then saves. */
function onReaderLoad_import(event) {
  const ttl = JSON.parse(event.target.result);
  let obj = ttl[0]
  let pid = ttl[1]
  let aid = ttl[2]
  for (let k in obj) {
    if (k in universe) {
      if (universe[k].timepoint < obj[k].timepoint) {
        universe[k] = obj[k]
      }
    } else {
      universe[k] = obj[k]
    }
  }
  for (let k in pid) {
    if (k in playerids) {
      if (playerids[k].timepoint < pid[k].timepoint) {
        playerids[k] = pid[k]
      }
    } else {
      playerids[k] = pid[k]
    }
  }
  for (let k in aid) {
    if (k in allyids) {
      if (allyids[k].timepoint < aid[k].timepoint) {
        allyids[k] = aid[k]
      }
    } else {
      allyids[k] = aid[k]
    }
  }
  galaxyStore.set("universe", universe)
  galaxyStore.set("playerids", playerids)
  galaxyStore.set("allyids", allyids)
}

/** Downloads the stored data as CSV: coordinates, moon flag, player and alliance (id in brackets), status; systems sorted by galaxy and system. */
function export_csv() {
  let keys = Object.keys(universe)
  keys.sort(compareFn)
  let outcsv = "coords,moon?,name,ally,status,\n"
  for (let k of keys) {
    for (let p = 1; p < 16; p++) {
      if (universe[k][p] !== null) {
        let hasmoon = universe[k][p].hasmoon ? 'X' : ''
        outcsv += k + ":" + p + "," + hasmoon + "," + playerids[universe[k][p].playerid].name + "(" + universe[k][p].playerid + ")" +
          "," + allyids[universe[k][p].allianceid].name + "(" + universe[k][p].allianceid + ")," + universe[k][p].special + ",\n"
      }
    }
  }
  download("universe.csv", outcsv)
}

/** Downloads the stored data as JSON ([universe, playerids, allyids]), the format import understands. */
function export_json() {
  download("universe.json", JSON.stringify([universe, playerids, allyids]))
}

/** After confirmation deletes all locally stored galaxy data. */
async function reset() {
  if (!(await window.Dialog.confirm(confirmlng, { danger: true }))) {
    return;
  }
  Object.keys(universe).forEach(key => delete universe[key]);
  Object.keys(playerids).forEach(key => delete playerids[key]);
  Object.keys(allyids).forEach(key => delete allyids[key]);
  galaxyStore.set("universe", universe)
  galaxyStore.set("playerids", playerids)
  galaxyStore.set("allyids", allyids)
}

/** Stores the currently viewed system, with its players and alliances, in the local galaxy data. */
function syncsys() {
  universe[galakey] = systemdata;
  for (let pos in systemdata) {
    if (pos === "timepoint" || systemdata[pos] === null) {
      continue
    }
    playerids[systemdata[pos].playerid] = {name: systemdata[pos].name, timepoint: systemdata.timepoint}
    allyids[systemdata[pos].allianceid] = {name: systemdata[pos].alliancename, timepoint: systemdata.timepoint}
  }
  galaxyStore.set("universe", universe)
  galaxyStore.set("playerids", playerids)
  galaxyStore.set("allyids", allyids)

}

/** Adds the known planet locations to the tooltip of every player name on the page. */
function showplayerinfos() {
  for (let username of document.getElementsByClassName(" galaxy-username")) {
    annotatehover(username.getAttribute("playerid"), username.parentElement)
  }
}

/** Appends a list of the player's known planets (from the local data, own system highlighted, links within the current galaxy emphasised) to the tooltip HTML of `htmlobject`. */
function annotatehover(id, htmlobject) {
  if (!htmlobject.hasAttribute("data-tooltip-content")) return;
  let anostr = htmlobject.getAttribute("data-tooltip-content").replace("</table>", "")
  anostr += "<tr><th colspan='2'>"+planetlng+"</th></tr>"
  let baseurl = location.href.split("?")[0] + "?page=galaxy&galaxy="
  let toshow = []
  for (let k in universe) {
    for (let p = 1; p < 16; p++) {
      if (universe[k][p] === null) {
        continue
      }
      if (universe[k][p].playerid == id) {
        let gala = parseInt(k.split(":")[0])
        let sys = parseInt(k.split(":")[1])
        toshow.push([gala, sys, p, universe[k][p].hasmoon ? moonshortlng : ''])
      }
    }
  }
  toshow.sort()
  let cgala = galakey.split(":")[0]
  let csys = galakey.split(":")[1]
  for (let coord of toshow) {
    if (coord[0] == cgala && coord[1] == csys) {
      anostr += "<tr><td colspan='2' style='text-align: center'><span>" + getcoordstring(coord) + "</span></td></tr>"
    } else {
      if (coord[0] == cgala) {
        anostr += "<tr><th colspan='2' style='text-align: center'><a href='" + baseurl + coord[0] + "&system=" + coord[1] + "'>" + getcoordstring(coord) + "</a></th></tr>"
      } else {
        anostr += "<tr><td colspan='2' style='text-align: center'><a href='" + baseurl + coord[0] + "&system=" + coord[1] + "'>" + getcoordstring(coord) + "</a></td></tr>"
      }
    }
  }

  htmlobject.setAttribute("data-tooltip-content", anostr + "</table>")
}

function getcoordstring(coord) {
  let x = "[" + coord[0] + ":" + coord[1] + ":" + coord[2] + "]" + " " + coord[3]
  return x.trim()
}


/** Sort order of "galaxy:system" keys: numeric by galaxy, then system. */
function compareFn(a, b) {
  let spa = a.split(":")
  let spb = b.split(":")
  if (parseInt(spa[0]) < parseInt(spb[0])) {
    return -1;
  } else {
    if (spa[0] !== spb[0]) {
      return 1
    }
  }
  if (parseInt(spa[1]) < parseInt(spb[1])) {
    return -1;
  } else {
    if (spa[1] !== spb[1]) {
      return 1
    }
  }
  return 0;
}

/** Offers `text` as a file download in the browser (data URL link, no server call). */
function download(filename, text) {
  const element = document.createElement('a');
  element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(text));
  element.setAttribute('download', filename);

  element.style.display = 'none';
  document.body.appendChild(element);

  element.click();

  document.body.removeChild(element);
}


let keypressRegistered = false;
document.addEventListener('keydown', logKey);

/** Keyboard navigation of the galaxy view: A / D or left / right arrows change the system, W / S or up / down the galaxy; only the first key press is handled (the page reloads). */
function logKey(e) {
  if (!keypressRegistered) {
    const callback = {
      "a": leftHandler,
      "ArrowLeft": leftHandler,
      "d": rightHandler,
      "ArrowRight": rightHandler,
      "w": upHandler,
      "ArrowUp": upHandler,
      "s": downHandler,
      "ArrowDown": downHandler,
    }[e.key]
    callback?.()
  }
}

function leftHandler() {
  keypressRegistered = true;
  galaxy_submit('systemLeft');
}

function rightHandler() {
  keypressRegistered = true;
  galaxy_submit('systemRight');
}

function upHandler() {
  keypressRegistered = true;
  galaxy_submit('galaxyLeft');
}

function downHandler() {
  keypressRegistered = true;
  galaxy_submit('galaxyRight');
}

registerActions({
  'galaxy-submit': (button) => galaxy_submit(button.dataset.direction),
  'galaxy-mission': (link) => doit(link.dataset.mission, link.dataset.planet),
  'missile-form': (link) => {
    const { planet, galaxy, system, position, duration, type } = link.dataset;
    return openMissileForm(planet, galaxy, system, position, duration, type);
  },
  /** "max" next to the missile count: all available missiles */
  'missiles-max': () => {
    document.getElementsByName('SendMI')[0].value = document.getElementById('missiles').textContent.replace(/\D/g, '');
  },
});
