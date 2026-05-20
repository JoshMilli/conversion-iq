import React from 'react';
import type { Branding } from './types';
import { T } from './theme';

interface FaqTabProps {
  B: Branding;
}

export default function FaqTab({ B }: FaqTabProps) {
  const defaultFaqs = [
    {
      q: `What is ${B.product} and how does it work?`,
      a: `${B.product} is an AI-powered conversion analysis tool that audits your website pages across six critical performance metrics: Conversion Clarity, Emotional Resonance, CTA Strength, Readability, Engagement, and Trust. It analyzes your actual content in the context of your business goals, target audience, and competitive landscape to provide specific, actionable recommendations for improving conversion rates.`
    },
    {
      q: `Why do I need ${B.company} if the AI provides recommendations?`,
      a: `While the AI identifies issues and suggests improvements, ${B.company} ensures proper implementation, testing, and optimization. Our team brings years of conversion expertise to interpret the data, prioritize changes based on impact, and execute solutions correctly. Think of it as the difference between a diagnostic report and professional treatment—both are necessary for optimal results.`
    },
    {
      q: "Are the suggestions personalized to my business?",
      a: "Yes. Every audit analyzes your specific page content, business objectives, target audience, and competitive context. The recommendations become increasingly refined as you run audits over time, especially after implementing changes. Additionally, each audit includes a complimentary 15-minute expert consultation with our team to provide personalized guidance."
    },
    {
      q: "What's included in the FREE 15-minute expert review?",
      a: `Each audit includes a complimentary consultation with a ${B.company} conversion specialist. During this session, we review your audit results, answer questions, help prioritize recommendations by impact, and provide guidance on implementation strategies. This ensures you understand your data and can make informed decisions about next steps.`
    },
    {
      q: "How is this different from SEO or analytics tools?",
      a: `Traditional tools focus on traffic acquisition and technical performance. ${B.product} focuses on what happens after visitors arrive—whether they understand your value proposition, trust your brand, and take desired actions. We analyze conversion psychology, message clarity, and persuasive elements that other tools don't measure.`
    },
    {
      q: "Can I implement changes myself?",
      a: `Yes, you can implement recommendations independently if you have the technical capability and conversion expertise. However, many clients choose to work with ${B.company} to ensure changes follow proven conversion patterns, avoid common pitfalls, and achieve measurable results faster. We provide implementation support at various service levels to match your needs.`
    },
    {
      q: "What is the 'Suggested Functionality' tab?",
      a: "Based on your audit results and business goals, this section recommends features or integrations that could enhance conversion performance—such as live chat, e-commerce capabilities, or marketing automation. Each recommendation explains why it would benefit your specific situation. These are optional suggestions to help you identify growth opportunities."
    },
    {
      q: "How often should I run audits?",
      a: "We recommend running audits: (1) As a baseline when starting, (2) After implementing significant changes, (3) Quarterly to track performance trends, (4) Before major campaigns or launches. The Automated Reports feature can schedule regular audits to maintain consistent monitoring without manual intervention."
    },
    {
      q: "What happens after I receive my audit results?",
      a: `You have several options: Review and implement suggestions independently, schedule your complimentary expert consultation for guidance, request a detailed implementation proposal from ${B.company}, or simply monitor your scores over time. The tool is designed to provide value at whatever level of engagement works for your business.`
    },
    {
      q: "How should I interpret the scoring system?",
      a: "Scores range from 0-100 across six metrics. Generally: 80+ indicates strong performance, 60-79 shows room for improvement, and below 60 suggests priority attention needed. However, context matters—your industry, audience, and goals affect what constitutes a 'good' score. Your expert review consultation can help interpret results specific to your situation."
    }
  ];

  const faqs = B.faqItems.length > 0 ? B.faqItems : defaultFaqs;

  return (
    <section style={{ background: T.bgCard, borderRadius: 16, boxShadow: '0 1px 3px rgba(0,0,0,0.3)', padding: 32 }}>
      <div style={{ marginBottom: 32, textAlign: 'center' }}>
        <h2 style={{ margin: '0 0 12px 0', fontSize: 28, fontWeight: 700, color: T.textPrimary }}>Frequently Asked Questions</h2>
        <p style={{ color: T.textSecondary, fontSize: 16, maxWidth: 700, margin: '0 auto' }}>
          Everything you need to know about {B.product} and how {B.company} can help you maximize your website's performance.
        </p>
      </div>

      <div style={{ maxWidth: 800, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 20 }}>
        {faqs.map((faq: any, i: number) => (
          <div
            key={i}
            style={{ background: T.bgSubtle, borderRadius: 12, padding: 24, border: `1px solid ${T.border}`, transition: 'all 0.2s' }}
            onMouseEnter={(e) => { e.currentTarget.style.borderColor = T.primary; e.currentTarget.style.boxShadow = '0 4px 12px rgba(245,158,11,0.15)'; }}
            onMouseLeave={(e) => { e.currentTarget.style.borderColor = T.border; e.currentTarget.style.boxShadow = 'none'; }}
          >
            <h3 style={{ margin: '0 0 12px 0', fontSize: 18, fontWeight: 700, color: T.textPrimary }}>{faq.q}</h3>
            <p style={{ margin: 0, color: T.textSecondary, lineHeight: 1.7, fontSize: 15 }}>{faq.a}</p>
          </div>
        ))}

        <div style={{ marginTop: 32, padding: 32, background: T.btnPrimary, borderRadius: 16, textAlign: 'center', color: T.btnPrimaryText }}>
          <h3 style={{ margin: '0 0 12px 0', fontSize: 24, fontWeight: 700 }}>Still Have Questions?</h3>
          <p style={{ margin: '0 0 20px 0', fontSize: 16, opacity: 0.95 }}>
            Our team is here to help. Schedule your FREE expert review or reach out with any questions.
          </p>
          <a
            href={`mailto:${B.supportEmail}?subject=${B.product} Question&body=Hi! I have a question about ${B.product}:%0D%0A%0D%0A[Your question here]`}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '12px 32px', background: T.bgCard, color: T.primary, textDecoration: 'none', borderRadius: 10, fontSize: 16, fontWeight: 600, transition: 'all 0.2s', boxShadow: '0 4px 12px rgba(0, 0, 0, 0.4)' }}
            onMouseEnter={(e) => { e.currentTarget.style.transform = 'translateY(-2px)'; e.currentTarget.style.boxShadow = '0 6px 16px rgba(0, 0, 0, 0.3)'; }}
            onMouseLeave={(e) => { e.currentTarget.style.transform = 'translateY(0)'; e.currentTarget.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.2)'; }}
          >
            📧 Contact {B.company} Support
          </a>
        </div>
      </div>
    </section>
  );
}
