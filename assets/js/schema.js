/* Field definitions.
   Forms and tables are generated from these lists, so a new field only has to
   be added here (plus in the database schema and the PHP whitelist) and it
   shows up everywhere with the correct input type and translation. */

const OPTS = {
  race: ['carnica', 'buckfast', 'mellifera', 'ligustica', 'caucasica', 'hybrid', 'other'],
  origin: ['swarm', 'split', 'package', 'purchase', 'overwintered', 'other'],
  hive_type: ['magazine', 'trog', 'top_bar', 'warre', 'mating_nuc', 'other'],
  frame_size: ['dnm', 'zander', 'langstroth', 'dadant', 'kuntzsch', 'other'],
  colony_status: ['active', 'weak', 'dead', 'sold', 'merged', 'swarmed'],
  marking_color: ['white', 'yellow', 'red', 'green', 'blue', 'unmarked'],
  mating_type: ['open', 'controlled', 'instrumental', 'unknown'],
  queen_cell_type: ['none', 'play', 'swarm', 'supersedure', 'emergency'],
  space_action: ['none', 'super_added', 'super_removed', 'frames_added', 'frames_removed', 'box_added'],
  varroa_method: ['board', 'powdered_sugar', 'alcohol_wash', 'co2', 'drone_brood'],
  health_status: ['ok', 'varroa', 'afb_suspicion', 'efb', 'chalkbrood', 'sacbrood', 'nosema',
                  'dwv', 'wax_moth', 'robbing', 'queenless', 'laying_workers', 'other'],
  feed_type: ['syrup_1_1', 'syrup_3_2', 'invert_syrup', 'fondant', 'honey', 'pollen', 'water', 'other'],
  unit: ['kg', 'l', 'ml', 'g', 'pcs'],
  treat_target: ['varroa', 'nosema', 'wax_moth', 'other'],
  treat_method: ['strips', 'trickling', 'spraying', 'evaporation', 'sublimation',
                 'drone_removal', 'brood_break', 'other'],
  event_type: ['swarm', 'swarm_caught', 'split', 'merge', 'requeening', 'queen_loss',
               'colony_loss', 'moved', 'maintenance', 'sampling', 'wintering', 'other'],
  priority: ['low', 'normal', 'high'],
  task_status: ['open', 'done'],
  role: ['admin', 'beekeeper', 'viewer'],
  temperament: ['1', '2', '3', '4', '5']
};

/* The international queen marking colour repeats every five years. */
const QUEEN_YEAR_COLORS = ['blue', 'white', 'yellow', 'red', 'green']; // year % 5

function queenColorForYear(year) {
  if (!year) return null;
  return QUEEN_YEAR_COLORS[Number(year) % 5];
}

const FORMS = {
  apiaries: [
    { n: 'name', t: 'text', req: true },
    { n: 'geo', t: 'geo', full: true },
    { n: 'code', t: 'text' },
    { n: 'address', t: 'text', full: true },
    { n: 'latitude', t: 'number', step: '0.000001' },
    { n: 'longitude', t: 'number', step: '0.000001' },
    { n: 'altitude', t: 'number' },
    { n: 'forage_notes', t: 'textarea', full: true },
    { n: 'description', t: 'textarea', full: true },
    { n: 'is_active', t: 'check', def: 1 }
  ],

  colonies: [
    { n: 'name', t: 'text', req: true },
    { n: 'tag_number', t: 'text' },
    { n: 'apiary_id', t: 'ref', ref: 'apiaries', req: true },
    { n: 'status', t: 'select', opts: 'colony_status', def: 'active' },
    { n: 'race', t: 'select', opts: 'race' },
    { n: 'origin', t: 'select', opts: 'origin' },
    { n: 'established_on', t: 'date' },
    { n: 'hive_type', t: 'select', opts: 'hive_type' },
    { n: 'frame_size', t: 'select', opts: 'frame_size' },
    { n: 'box_count', t: 'number', min: 0, max: 12 },
    { n: 'parent_colony_id', t: 'ref', ref: 'colonies' },
    { n: 'notes', t: 'textarea', full: true }
  ],

  queens: [
    { n: 'colony_id', t: 'ref', ref: 'colonies', req: true },
    { n: 'name', t: 'text' },
    { n: 'birth_year', t: 'number', min: 1990, max: 2100 },
    { n: 'marking_color', t: 'select', opts: 'marking_color' },
    { n: 'race', t: 'select', opts: 'race' },
    { n: 'mating_type', t: 'select', opts: 'mating_type' },
    { n: 'breeder', t: 'text' },
    { n: 'origin', t: 'select', opts: 'origin' },
    { n: 'introduced_on', t: 'date' },
    { n: 'removed_on', t: 'date' },
    { n: 'is_clipped', t: 'check' },
    { n: 'is_current', t: 'check', def: 1 },
    { n: 'notes', t: 'textarea', full: true }
  ],

  inspections: [
    { section: 'inspections.section_general' },
    { n: 'colony_id', t: 'ref', ref: 'colonies', req: true },
    { n: 'inspected_at', t: 'datetime', req: true, now: true },
    { n: 'duration_min', t: 'number', min: 0 },
    { n: 'weather', t: 'weather' },

    { section: 'inspections.section_colony' },
    { n: 'strength_frames', t: 'number', step: '0.5', min: 0, max: 40 },
    { n: 'brood_frames', t: 'number', step: '0.5', min: 0, max: 40 },
    { n: 'temperament', t: 'select', opts: 'temperament' },
    { n: 'hive_weight_kg', t: 'number', step: '0.1', min: 0 },

    { section: 'inspections.section_queen' },
    { n: 'queen_seen', t: 'check' },
    { n: 'eggs_seen', t: 'check' },
    { n: 'larvae_seen', t: 'check' },
    { n: 'capped_brood_seen', t: 'check' },
    { n: 'drone_brood', t: 'check' },
    { n: 'swarm_risk', t: 'check' },
    { n: 'queen_cell_type', t: 'select', opts: 'queen_cell_type' },
    { n: 'queen_cell_count', t: 'number', min: 0 },

    { section: 'inspections.section_health' },
    { n: 'health_status', t: 'select', opts: 'health_status' },
    { n: 'varroa_count', t: 'number', min: 0 },
    { n: 'varroa_method', t: 'select', opts: 'varroa_method' },
    { n: 'varroa_days', t: 'number', min: 1 },

    { section: 'inspections.section_stores' },
    { n: 'stores_kg', t: 'number', step: '0.5', min: 0 },
    { n: 'supers_count', t: 'number', min: 0, max: 8 },
    { n: 'space_action', t: 'select', opts: 'space_action' },

    { section: 'inspections.section_notes' },
    { n: 'notes', t: 'textarea', full: true }
  ],

  feedings: [
    { n: 'colony_id', t: 'ref', ref: 'colonies', req: true },
    { n: 'fed_at', t: 'datetime', req: true, now: true },
    { n: 'feed_type', t: 'select', opts: 'feed_type', req: true, def: 'syrup_3_2' },
    { n: 'amount', t: 'number', step: '0.1', min: 0 },
    { n: 'unit', t: 'select', opts: 'unit', def: 'kg' },
    { n: 'notes', t: 'textarea', full: true }
  ],

  treatments: [
    { n: 'colony_id', t: 'ref', ref: 'colonies', req: true },
    { n: 'started_at', t: 'date', req: true, today: true },
    { n: 'ended_at', t: 'date' },
    { n: 'target', t: 'select', opts: 'treat_target', def: 'varroa' },
    { n: 'product', t: 'text' },
    { n: 'active_substance', t: 'text' },
    { n: 'dose', t: 'number', step: '0.1', min: 0 },
    { n: 'unit', t: 'select', opts: 'unit', def: 'ml' },
    { n: 'method', t: 'select', opts: 'treat_method' },
    { n: 'temperature_c', t: 'number', step: '0.1' },
    { n: 'batch_no', t: 'text' },
    { n: 'withdrawal_until', t: 'date' },
    { n: 'notes', t: 'textarea', full: true }
  ],

  harvests: [
    { n: 'colony_id', t: 'ref', ref: 'colonies', req: true },
    { n: 'harvested_at', t: 'date', req: true, today: true },
    { n: 'honey_type', t: 'text' },
    { n: 'frames_count', t: 'number', min: 0 },
    { n: 'gross_kg', t: 'number', step: '0.01', min: 0 },
    { n: 'net_kg', t: 'number', step: '0.01', min: 0 },
    { n: 'water_content', t: 'number', step: '0.1', min: 0, max: 30 },
    { n: 'jars_count', t: 'number', min: 0 },
    { n: 'batch_no', t: 'text' },
    { n: 'notes', t: 'textarea', full: true }
  ],

  events: [
    { n: 'colony_id', t: 'ref', ref: 'colonies' },
    { n: 'apiary_id', t: 'ref', ref: 'apiaries' },
    { n: 'event_at', t: 'datetime', req: true, now: true },
    { n: 'event_type', t: 'select', opts: 'event_type', req: true },
    { n: 'title', t: 'text', full: true },
    { n: 'notes', t: 'textarea', full: true }
  ],

  tasks: [
    { n: 'title', t: 'text', req: true, full: true },
    { n: 'colony_id', t: 'ref', ref: 'colonies' },
    { n: 'apiary_id', t: 'ref', ref: 'apiaries' },
    { n: 'due_date', t: 'date' },
    { n: 'priority', t: 'select', opts: 'priority', def: 'normal' },
    { n: 'status', t: 'select', opts: 'task_status', def: 'open' },
    { n: 'user_id', t: 'ref', ref: 'users' },
    { n: 'description', t: 'textarea', full: true }
  ],

  users: [
    { n: 'username', t: 'text', req: true, label: 'common.username' },
    { n: 'full_name', t: 'text' },
    { n: 'email', t: 'text' },
    { n: 'role', t: 'select', opts: 'role', def: 'beekeeper' },
    { n: 'locale', t: 'select', opts: 'locale_opts', def: 'de' },
    { n: 'password', t: 'password', hint: 'users.password_hint' },
    { n: 'is_active', t: 'check', def: 1 }
  ]
};

/* Columns shown in the record tables. kind: date | text | num | bool | opt */
const COLUMNS = {
  inspections: [
    { n: 'inspected_at', kind: 'datetime' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'strength_frames', kind: 'num' },
    { n: 'brood_frames', kind: 'num' },
    { n: 'queen_seen', kind: 'bool' },
    { n: 'queen_cell_type', kind: 'opt', opts: 'queen_cell_type' },
    { n: 'varroa_count', kind: 'num' },
    { n: 'health_status', kind: 'opt', opts: 'health_status' },
    { n: 'weather_temp', kind: 'num', suffix: ' °C', label: 'weather.temp' },
    { n: 'notes', kind: 'text' }
  ],
  feedings: [
    { n: 'fed_at', kind: 'datetime' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'feed_type', kind: 'opt', opts: 'feed_type' },
    { n: 'amount', kind: 'num' },
    { n: 'unit', kind: 'opt', opts: 'unit' },
    { n: 'notes', kind: 'text' }
  ],
  treatments: [
    { n: 'started_at', kind: 'date' },
    { n: 'ended_at', kind: 'date' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'target', kind: 'opt', opts: 'treat_target' },
    { n: 'product', kind: 'text' },
    { n: 'dose', kind: 'num' },
    { n: 'unit', kind: 'opt', opts: 'unit' },
    { n: 'method', kind: 'opt', opts: 'treat_method' },
    { n: 'withdrawal_until', kind: 'date' }
  ],
  harvests: [
    { n: 'harvested_at', kind: 'date' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'honey_type', kind: 'text' },
    { n: 'frames_count', kind: 'num' },
    { n: 'net_kg', kind: 'num' },
    { n: 'water_content', kind: 'num', suffix: ' %' },
    { n: 'jars_count', kind: 'num' },
    { n: 'batch_no', kind: 'text' }
  ],
  events: [
    { n: 'event_at', kind: 'datetime' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'event_type', kind: 'opt', opts: 'event_type' },
    { n: 'title', kind: 'text' },
    { n: 'notes', kind: 'text' }
  ],
  tasks: [
    { n: 'due_date', kind: 'date' },
    { n: 'title', kind: 'text' },
    { n: 'colony_name', kind: 'text', label: 'common.colony' },
    { n: 'priority', kind: 'opt', opts: 'priority' },
    { n: 'status', kind: 'opt', opts: 'task_status' },
    { n: 'assignee_name', kind: 'text', label: 'field.user_id' }
  ],
  queens: [
    { n: 'name', kind: 'text' },
    { n: 'birth_year', kind: 'num' },
    { n: 'marking_color', kind: 'opt', opts: 'marking_color' },
    { n: 'race', kind: 'opt', opts: 'race' },
    { n: 'mating_type', kind: 'opt', opts: 'mating_type' },
    { n: 'introduced_on', kind: 'date' },
    { n: 'removed_on', kind: 'date' },
    { n: 'is_current', kind: 'bool' }
  ]
};

/* Record types offered in the report filter. */
const REPORT_TYPES = ['inspections', 'feedings', 'treatments', 'harvests', 'events', 'tasks'];

/* The column each record type is dated by. The full protocol shows it in the
   record header, so the field list below it skips it. */
const REPORT_DATE_FIELD = {
  inspections: 'inspected_at', feedings: 'fed_at', treatments: 'started_at',
  harvests: 'harvested_at', events: 'event_at', tasks: 'due_date'
};
