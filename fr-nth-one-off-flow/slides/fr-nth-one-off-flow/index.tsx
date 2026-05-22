import type { DesignSystem, Page, SlideMeta } from '@open-slide/core';

export const design: DesignSystem = {
  palette: {
    bg: '#f4f1ea',
    text: '#182024',
    accent: '#0f766e',
  },
  fonts: {
    display: '"Aptos Display", "Segoe UI", system-ui, sans-serif',
    body: '"Aptos", "Segoe UI", system-ui, sans-serif',
  },
  typeScale: {
    hero: 156,
    body: 34,
  },
  radius: 10,
};

const colors = {
  paper: '#fffaf0',
  ink: '#182024',
  teal: '#0f766e',
  coral: '#d95d39',
  blue: '#235789',
  gold: '#c98b1d',
  green: '#3f7d20',
  muted: '#5f6a6f',
  line: '#cfc7b8',
  soft: '#e8dfcf',
  dark: '#20292d',
};

const fill = {
  width: '100%',
  height: '100%',
  background: 'var(--osd-bg)',
  color: 'var(--osd-text)',
  fontFamily: 'var(--osd-font-body)',
  position: 'relative' as const,
  overflow: 'hidden',
};

const mono = '"Cascadia Code", "SF Mono", Consolas, monospace';

const Footer = ({ label }: { label: string }) => (
  <div
    style={{
      position: 'absolute',
      left: 120,
      right: 120,
      bottom: 54,
      display: 'flex',
      justifyContent: 'space-between',
      borderTop: `2px solid ${colors.line}`,
      paddingTop: 20,
      fontSize: 24,
      color: colors.muted,
    }}
  >
    <span>FR NTH one-off user journey</span>
    <span>{label}</span>
  </div>
);

const Tag = ({ children, tone = colors.teal }: { children: React.ReactNode; tone?: string }) => (
  <span
    style={{
      display: 'inline-flex',
      alignItems: 'center',
      border: `2px solid ${tone}`,
      borderRadius: 999,
      color: tone,
      fontSize: 24,
      fontWeight: 800,
      padding: '8px 18px',
      textTransform: 'uppercase',
      letterSpacing: 0,
    }}
  >
    {children}
  </span>
);

const Node = ({
  title,
  body,
  color,
}: {
  title: string;
  body: string;
  color: string;
}) => (
  <div
    style={{
      background: colors.paper,
      border: `3px solid ${color}`,
      borderRadius: 12,
      padding: '28px 30px',
      minHeight: 170,
      boxShadow: '10px 10px 0 rgba(24,32,36,0.10)',
    }}
  >
    <div style={{ fontSize: 32, fontWeight: 900, color }}>{title}</div>
    <div style={{ marginTop: 14, fontSize: 27, lineHeight: 1.35, color: colors.ink }}>{body}</div>
  </div>
);

const Column = ({
  title,
  children,
  color,
}: {
  title: string;
  children: React.ReactNode;
  color: string;
}) => (
  <div
    style={{
      background: colors.paper,
      border: `3px solid ${color}`,
      borderRadius: 12,
      padding: 32,
      minHeight: 620,
      boxShadow: '10px 10px 0 rgba(24,32,36,0.10)',
    }}
  >
    <div style={{ fontSize: 34, fontWeight: 900, color, marginBottom: 28 }}>{title}</div>
    {children}
  </div>
);

const CodeLine = ({ children }: { children: React.ReactNode }) => (
  <div
    style={{
      fontFamily: mono,
      fontSize: 23,
      lineHeight: 1.4,
      color: colors.dark,
      background: '#f7efe2',
      border: `1px solid ${colors.line}`,
      borderRadius: 8,
      padding: '10px 14px',
      marginTop: 12,
    }}
  >
    {children}
  </div>
);

const Bullet = ({ children }: { children: React.ReactNode }) => (
  <li style={{ marginBottom: 20, lineHeight: 1.35 }}>{children}</li>
);

const Cover: Page = () => (
  <div style={{ ...fill, padding: '118px 130px' }}>
    <div
      style={{
        position: 'absolute',
        inset: '52px 64px',
        border: `3px solid ${colors.ink}`,
        borderRadius: 18,
        pointerEvents: 'none',
      }}
    />
    <div style={{ display: 'flex', gap: 18, marginBottom: 58 }}>
      <Tag>NTH</Tag>
      <Tag tone={colors.blue}>France</Tag>
      <Tag tone={colors.coral}>One-off Premium SMS</Tag>
    </div>
    <h1
      style={{
        fontFamily: 'var(--osd-font-display)',
        fontSize: 'var(--osd-size-hero)',
        lineHeight: 0.98,
        fontWeight: 950,
        margin: 0,
        maxWidth: 1360,
        letterSpacing: 0,
      }}
    >
      User-flow Map:
      <br />
      FR-NTH-one-off
    </h1>
    <p style={{ marginTop: 44, fontSize: 42, lineHeight: 1.35, maxWidth: 1280, color: colors.muted }}>
      Wo Landing, Attribution, KPI, NTH Callback, Sale und Postback initialisiert,
      getrackt und persistiert werden.
    </p>
    <div style={{ position: 'absolute', right: 130, bottom: 115, fontSize: 32, fontWeight: 900 }}>
      shortcode 84072 / keyword Jplay*
    </div>
  </div>
);

const Overview: Page = () => (
  <div style={{ ...fill, padding: '102px 118px' }}>
    <h2 style={{ fontSize: 72, margin: 0, fontWeight: 950 }}>End-to-end Journey</h2>
    <div style={{ marginTop: 58, display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 30 }}>
      <Node title="1 Landing" body="Router rendert LP, erfasst Click Attribution und baut den sms:// CTA." color={colors.teal} />
      <Node title="2 CTA + SMS" body="Client trackt Page Load, CTA und SMS-Handoff; User sendet MO an 84072." color={colors.blue} />
      <Node title="3 NTH MO" body="deliverMessage trifft im REST Callback ein und startet die MT Billing Verarbeitung." color={colors.coral} />
      <Node title="4 MT Submit" body="Backend sendet premium MT mit price=450, messageRef und sessionId an NTH." color={colors.gold} />
      <Node title="5 Report" body="deliverReport bestaetigt finalen MT Status und triggert Sales/Conversion Logik." color={colors.green} />
      <Node title="6 Analytics" body="Attribution, KPI, Handoff, Variant, Sales und Views bilden den Funnel." color={colors.dark} />
    </div>
    <Footer label="01 / map" />
  </div>
);

const Initialization: Page = () => (
  <div style={{ ...fill, padding: '98px 118px' }}>
    <h2 style={{ fontSize: 68, margin: 0, fontWeight: 950 }}>Was wird wo initialisiert?</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 34, marginTop: 54 }}>
      <Column title="Landing-Kontext" color={colors.teal}>
        <ul style={{ fontSize: 30, margin: 0, paddingLeft: 34 }}>
          <Bullet><b>Filesystem LP:</b> `landing-pages/lp5-fr/integration.php`</Bullet>
          <Bullet><b>Flow:</b> `nth-fr-one-off`, provider `nth`, service `nth_fr_one_off_jplay`</Bullet>
          <Bullet><b>CTA:</b> <code>{'{{KIWI_PRIMARY_CTA_HREF}}'}</code> wird zentral ersetzt</Bullet>
          <Bullet><b>KPI selector:</b> `.cta` als `cta1` aus `kpi_cta_steps`</Bullet>
        </ul>
        <CodeLine>Kiwi_Landing_Page_Router</CodeLine>
        <CodeLine>Kiwi_Nth_Primary_Cta_Adapter</CodeLine>
      </Column>
      <Column title="Runtime-Services" color={colors.blue}>
        <ul style={{ fontSize: 30, margin: 0, paddingLeft: 34 }}>
          <Bullet><b>Plugin bootstrap:</b> Repositories und Services werden in `Kiwi_Plugin` verdrahtet</Bullet>
          <Bullet><b>Attribution:</b> Tracking Capture + Click Attribution Repository</Bullet>
          <Bullet><b>KPI:</b> Landing KPI Service + REST routes + Engagement/Handoff repos</Bullet>
          <Bullet><b>NTH:</b> Client, Normalizer, Event Repo, Flow Transaction Repo, Sales Recorder</Bullet>
        </ul>
        <CodeLine>Kiwi_Tracking_Capture_Service</CodeLine>
        <CodeLine>Kiwi_Nth_Fr_One_Off_Service</CodeLine>
      </Column>
    </div>
    <Footer label="02 / init" />
  </div>
);

const ClientTracking: Page = () => (
  <div style={{ ...fill, padding: '98px 118px' }}>
    <h2 style={{ fontSize: 68, margin: 0, fontWeight: 950 }}>Was wird im Browser getrackt?</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 28, marginTop: 54 }}>
      <Column title="Page Load" color={colors.teal}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Tracker Script wird in gerenderte Landing Page injiziert.</Bullet>
          <Bullet>Session Token und Landing Metadata gehen an KPI REST Route.</Bullet>
          <Bullet>Optional werden UA Client Hints beim erlaubten Tracking Mode mitgegeben.</Bullet>
        </ul>
        <CodeLine>event: page_loaded</CodeLine>
      </Column>
      <Column title="CTA Click" color={colors.blue}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Selector kommt aus `kpi_cta_steps`, hier `.cta` als `cta1`.</Bullet>
          <Bullet>Click Count und first/last CTA timestamps werden sessionbezogen aktualisiert.</Bullet>
          <Bullet>Source Context bleibt an Session und Attribution anschlussfaehig.</Bullet>
        </ul>
        <CodeLine>event: cta_click / cta1</CodeLine>
      </Column>
      <Column title="SMS Handoff" color={colors.coral}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Bei sms/smsto Link werden Attempt, Hidden, Return oder No-hide Events erzeugt.</Bullet>
          <Bullet>Handoff speichert Recipient, Body-Presence und Transaction-Token-Hinweis.</Bullet>
          <Bullet>Das aendert KPI Counter nicht, liefert aber Funnel-Diagnostik.</Bullet>
        </ul>
        <CodeLine>event: sms_handoff_*</CodeLine>
      </Column>
    </div>
    <Footer label="03 / client tracking" />
  </div>
);

const LandingTables: Page = () => (
  <div style={{ ...fill, padding: '96px 118px' }}>
    <h2 style={{ fontSize: 66, margin: 0, fontWeight: 950 }}>Landing-seitige Writes</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1.02fr 1fr', gap: 34, marginTop: 52 }}>
      <Column title="Attribution" color={colors.teal}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>`clickid` und optionale Source Felder werden aus Query Params gelesen.</Bullet>
          <Bullet>Cookie bekommt nur `tracking_token`, nicht den rohen Click ID.</Bullet>
          <Bullet>Interne `transaction_id` ist die spaetere Korrelationswurzel.</Bullet>
        </ul>
        <CodeLine>wp_kiwi_click_attributions</CodeLine>
      </Column>
      <Column title="Engagement + Handoff" color={colors.blue}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Landing Engagement haelt Page Load, CTA Zeiten und Click Count.</Bullet>
          <Bullet>Handoff Events speichern SMS Uebergang und Browser Transition Evidence.</Bullet>
          <Bullet>Beide tragen `landing_key`, `service_key`, `session_token` und Source Snapshot.</Bullet>
        </ul>
        <CodeLine>wp_kiwi_premium_sms_landing_engagements</CodeLine>
        <CodeLine>wp_kiwi_landing_handoff_events</CodeLine>
      </Column>
    </div>
    <Footer label="04 / landing writes" />
  </div>
);

const NthProcessing: Page = () => (
  <div style={{ ...fill, padding: '96px 118px' }}>
    <h2 style={{ fontSize: 66, margin: 0, fontWeight: 950 }}>NTH Callback und MT Billing</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 34, marginTop: 52 }}>
      <Column title="MO: deliverMessage" color={colors.coral}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>`/wp-json/kiwi-backend/v1/nth-callback` dispatcht nach command.</Bullet>
          <Bullet>Normalizer prueft Business Number, Keyword, Operator Code und Session.</Bullet>
          <Bullet>Token wird aus `JPLAY txn_...` oder Variant Alias zur internen `transaction_id` aufgeloest.</Bullet>
          <Bullet>FR MSISDN ist verschluesselt, nicht echte Rufnummer.</Bullet>
        </ul>
      </Column>
      <Column title="MT: submitMessage" color={colors.gold}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Premium Charge liegt auf outbound MT, nicht auf der MO.</Bullet>
          <Bullet>Payload nutzt `price=450`, NWC Operator Mapping und NTH `sessionId`.</Bullet>
          <Bullet>`messageRef` basiert auf Flow Reference / `txn_...` fuer Sale-Korrelation.</Bullet>
          <Bullet>MT Text muss Preis und "kein Abo" enthalten.</Bullet>
        </ul>
      </Column>
    </div>
    <Footer label="05 / nth processing" />
  </div>
);

const Conversion: Page = () => (
  <div style={{ ...fill, padding: '96px 118px' }}>
    <h2 style={{ fontSize: 66, margin: 0, fontWeight: 950 }}>Wann wird Sale geschrieben?</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 34, marginTop: 52 }}>
      <Column title="Report: deliverReport" color={colors.green}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>NTH liefert MT Status mit `messageRef`, `messageId`, `sessionId`.</Bullet>
          <Bullet>Service findet passende Flow Transaction und normalisiert Terminalstatus.</Bullet>
          <Bullet>Nur erfolgreicher terminaler Report fuehrt zur Sale-Persistenz.</Bullet>
          <Bullet>HTTP 200 bestaetigt Callback Annahme.</Bullet>
        </ul>
        <CodeLine>Kiwi_Nth_Fr_One_Off_Service</CodeLine>
      </Column>
      <Column title="Sale + Postback" color={colors.teal}>
        <ul style={{ fontSize: 29, margin: 0, paddingLeft: 32 }}>
          <Bullet>Shared Sales Recorder schreibt oder aktualisiert den One-off Sale.</Bullet>
          <Bullet>Resolver matched Attribution ueber `transaction_id` und stabile Refs.</Bullet>
          <Bullet>Affiliate Postback geht nur bei confirmed Conversion und idempotent.</Bullet>
          <Bullet>Sales koennen mit Source Context wie `pid` angereichert werden.</Bullet>
        </ul>
        <CodeLine>wp_kiwi_sales</CodeLine>
      </Column>
    </div>
    <Footer label="06 / conversion" />
  </div>
);

const TableMatrix: Page = () => (
  <div style={{ ...fill, padding: '88px 104px' }}>
    <h2 style={{ fontSize: 64, margin: 0, fontWeight: 950 }}>Persistenz-Matrix</h2>
    <div
      style={{
        marginTop: 42,
        display: 'grid',
        gridTemplateColumns: '350px 1fr 1fr',
        border: `3px solid ${colors.ink}`,
        background: colors.paper,
        boxShadow: '10px 10px 0 rgba(24,32,36,0.10)',
      }}
    >
      <div style={{ ...cellHead, borderTop: 0, borderLeft: 0 }}>Tabelle</div>
      <div style={{ ...cellHead, borderTop: 0 }}>Wann</div>
      <div style={{ ...cellHead, borderTop: 0, borderRight: 0 }}>Wofuer</div>
      <div style={{ ...cell, borderLeft: 0 }}>click_attributions</div>
      <div style={cell}>Landing Entry</div>
      <div style={{ ...cell, borderRight: 0 }}>Click ID, token, transaction_id, refs, postback audit</div>
      <div style={{ ...cell, borderLeft: 0 }}>landing_engagements</div>
      <div style={cell}>Page load / CTA</div>
      <div style={{ ...cell, borderRight: 0 }}>Session evidence, timestamps, CTA count, source snapshot</div>
      <div style={{ ...cell, borderLeft: 0 }}>handoff_events</div>
      <div style={cell}>sms:// transition</div>
      <div style={{ ...cell, borderRight: 0 }}>Attempt, hidden, returned, no-hide diagnostics</div>
      <div style={{ ...cell, borderLeft: 0 }}>variant_assignments</div>
      <div style={cell}>CTA rendering</div>
      <div style={{ ...cell, borderRight: 0 }}>Visible token alias, variant, CTA/handoff/conv markers</div>
      <div style={{ ...cell, borderLeft: 0, borderBottom: 0 }}>sales</div>
      <div style={{ ...cell, borderBottom: 0 }}>Successful report</div>
      <div style={{ ...cell, borderRight: 0, borderBottom: 0 }}>Completed one-off sale and correlation root</div>
    </div>
    <Footer label="07 / table map" />
  </div>
);

const cellHead = {
  fontSize: 27,
  fontWeight: 950,
  padding: '20px 22px',
  background: colors.dark,
  color: '#fffaf0',
  borderTop: `2px solid ${colors.ink}`,
  borderLeft: `2px solid ${colors.ink}`,
  borderRight: `2px solid ${colors.ink}`,
  borderBottom: `2px solid ${colors.ink}`,
} as const;

const cell = {
  fontSize: 26,
  lineHeight: 1.28,
  padding: '18px 22px',
  borderTop: `2px solid ${colors.ink}`,
  borderLeft: `2px solid ${colors.ink}`,
  borderRight: `2px solid ${colors.ink}`,
  borderBottom: `2px solid ${colors.ink}`,
} as const;

const OpenEdges: Page = () => (
  <div style={{ ...fill, padding: '98px 118px' }}>
    <h2 style={{ fontSize: 68, margin: 0, fontWeight: 950 }}>Offene Kanten fuer Review</h2>
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 34, marginTop: 54 }}>
      <Column title="Pruefen im Setup" color={colors.coral}>
        <ul style={{ fontSize: 30, margin: 0, paddingLeft: 34 }}>
          <Bullet>NTH Callback-Konfiguration fuer `deliverMessage` und `deliverReport` bestaetigen.</Bullet>
          <Bullet>Operator/NWC Mapping gegen aktuelle NTH Service Config validieren.</Bullet>
          <Bullet>Orange Timing-Regel: MT innerhalb von 24h nach MO sicherstellen.</Bullet>
          <Bullet>FR Text: Preis und "kein Abo" in MT und Landing sichtbar halten.</Bullet>
        </ul>
      </Column>
      <Column title="Architektur-Leitplanke" color={colors.teal}>
        <ul style={{ fontSize: 30, margin: 0, paddingLeft: 34 }}>
          <Bullet>NTH Payloads bleiben im Provider Layer.</Bullet>
          <Bullet>Shared Tabellen speichern normalisierte interne Semantik.</Bullet>
          <Bullet>Sales und Postbacks haengen an confirmed Conversion, nicht an rohem Callback.</Bullet>
          <Bullet>Weitere Provider sollen dieselbe Attribution/Conversion Capability nutzen.</Bullet>
        </ul>
      </Column>
    </div>
    <Footer label="08 / review" />
  </div>
);

export const meta: SlideMeta = {
  title: 'FR NTH one-off user-flow',
  createdAt: '2026-05-22T13:21:25.997Z',
};

export default [
  Cover,
  Overview,
  Initialization,
  ClientTracking,
  LandingTables,
  NthProcessing,
  Conversion,
  TableMatrix,
  OpenEdges,
] satisfies Page[];
