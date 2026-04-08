export type Suggestion = {
  text: string;
  section?: string;
  why?: string;
  impact?: string;
  implementation?: string;
};

export type Audit = {
  insert_id?: number;
  overall_score?: number;
  clarity_score?: number;
  emotional_score?: number;
  cta_strength?: number;
  readability_score?: number;
  engagement_score?: number;
  trust_score?: number;
  suggestions?: Suggestion[] | string[];
  functionality_suggestions?: {
    title: string;
    description: string;
    why: string;
    icon?: string;
  }[];
  rewrites?: Record<string, string>;
  page_id?: number;
  page_title?: string;
  page_url?: string;
  ai_used?: boolean;
  created_at?: string;
  content_changed?: boolean;
  insights?: {
    strengths?: string[];
    weaknesses?: string[];
    opportunities?: string[];
    audience_alignment?: string;
    tone_analysis?: string;
    executive_summary?: string;
    top_priority_insight?: string;
  };
  recommendations?: {
    quick_wins?: Array<string | { text: string; why?: string; impact?: string; difficulty?: string }>;
    long_term?: Array<string | { text: string; why?: string; impact?: string; difficulty?: string; timeframe?: string }>;
    priority?: string | { text: string; why?: string; impact?: string; next_steps?: string };
  };
};

export type Page = { id: number; title: string; permalink: string };

export type Branding = {
  company: string;
  product: string;
  supportEmail: string;
  websiteUrl: string;
  contactUrl: string;
  primaryColor: string;
  accentColor: string;
  logoUrl: string;
  hidePoweredBy: boolean;
  faqItems: any[];
};
