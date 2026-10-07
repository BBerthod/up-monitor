import { definePreset } from '@primevue/themes';
import Aura from '@primevue/themes/aura';

/*
 * Instrument-console preset: flat opaque surfaces, 1px hairline borders,
 * 4px (badges) / 6px (buttons, fields, panels) radii, no glow.
 * Keep these values in sync with the @theme tokens in resources/css/app.css.
 */
const text = {
    primary: '#e8e8ea',
    secondary: '#9a9aa1',
    muted: '#85858c',
};

const MyPreset = definePreset(Aura, {
    primitive: {
        borderRadius: {
            none: '0',
            xs: '2px',
            sm: '4px',
            md: '6px',
            lg: '6px',
            xl: '6px',
        },
    },
    semantic: {
        primary: {
            50: '{emerald.50}',
            100: '{emerald.100}',
            200: '{emerald.200}',
            300: '{emerald.300}',
            400: '{emerald.400}',
            500: '{emerald.500}',
            600: '{emerald.600}',
            700: '{emerald.700}',
            800: '{emerald.800}',
            900: '{emerald.900}',
            950: '{emerald.950}'
        },
        colorScheme: {
            dark: {
                // Console greys: 950 page, 900 panel, 800 raised, 700 hairline.
                surface: {
                    0: '#ffffff',
                    50: '#f4f4f5',
                    100: '#e8e8ea',
                    200: '#d4d4d8',
                    300: '#bdbdc3',
                    400: '#9a9aa1',
                    500: '#6b6b72',
                    600: '#34353b',
                    700: '#26272c',
                    800: '#1a1b1f',
                    900: '#131417',
                    950: '#0b0c0e'
                },
                primary: {
                    color: '{primary.500}',
                    contrastColor: '#0b0c0e',
                    hoverColor: '{primary.400}',
                    activeColor: '{primary.400}'
                },
                highlight: {
                    background: 'rgba(16,185,129,0.12)',
                    focusBackground: 'rgba(16,185,129,0.18)',
                    color: '#34d399',
                    focusColor: '#34d399'
                },
                text: {
                    color: text.primary,
                    hoverColor: '#ffffff',
                    mutedColor: text.secondary,
                    hoverMutedColor: text.primary
                },
                formField: {
                    background: '{surface.950}',
                    borderColor: '{surface.600}',
                    hoverBorderColor: '#4a4b52',
                    color: text.primary,
                    placeholderColor: '{surface.500}',
                    iconColor: text.secondary,
                    shadow: 'none'
                },
                overlay: {
                    select: { shadow: '0 4px 16px rgba(0,0,0,0.45)' },
                    popover: { shadow: '0 4px 16px rgba(0,0,0,0.45)' },
                    modal: { shadow: '0 8px 32px rgba(0,0,0,0.5)' }
                }
            }
        }
    },
    components: {
        button: {
            borderRadius: '6px',
            colorScheme: {
                dark: {
                    root: {
                        primary: {
                            background: '#10b981',
                            border: { color: '#10b981' },
                            color: '#0b0c0e',
                            hover: {
                                background: '#34d399',
                                border: { color: '#34d399' },
                                color: '#0b0c0e'
                            },
                            active: {
                                background: '#34d399',
                                border: { color: '#34d399' },
                                color: '#0b0c0e'
                            }
                        },
                        secondary: {
                            background: '#1a1b1f',
                            border: { color: '#26272c' },
                            color: text.primary,
                            hover: {
                                background: '#202126',
                                border: { color: '#34353b' },
                                color: text.primary
                            }
                        },
                        danger: {
                            background: 'transparent',
                            border: { color: 'rgba(239,68,68,0.4)' },
                            color: '#f87171',
                            hover: {
                                background: 'rgba(239,68,68,0.1)',
                                border: { color: 'rgba(239,68,68,0.6)' },
                                color: '#f87171'
                            }
                        }
                    }
                }
            }
        },
        datatable: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#131417',
                        borderColor: 'transparent'
                    },
                    header: {
                        background: '#131417',
                        borderColor: 'transparent'
                    },
                    headerCell: {
                        background: '#131417',
                        color: text.muted,
                        borderColor: '#26272c'
                    },
                    bodyCell: {
                        background: 'transparent',
                        color: text.primary,
                        borderColor: '#26272c'
                    },
                    row: {
                        background: 'transparent'
                    },
                    rowHover: {
                        background: '#1a1b1f'
                    },
                    sortIcon: {
                        color: text.muted
                    }
                }
            }
        },
        dataview: {
            colorScheme: {
                dark: {
                    content: {
                        background: 'transparent',
                        borderColor: 'transparent',
                        color: text.primary
                    }
                }
            }
        },
        selectbutton: {
            colorScheme: {
                dark: {
                    root: {
                        borderRadius: '6px'
                    },
                    toggleButton: {
                        background: 'transparent',
                        borderColor: '#26272c',
                        color: text.secondary,
                        hoverBackground: '#1a1b1f',
                        highlightBackground: '#26272c',
                        highlightColor: text.primary,
                        highlightBorderColor: '#34353b'
                    }
                }
            }
        },
        tag: {
            borderRadius: '4px',
            colorScheme: {
                dark: {
                    success: {
                        background: 'rgba(154,154,161,0.12)',
                        color: text.secondary
                    },
                    danger: {
                        background: 'rgba(239,68,68,0.12)',
                        color: '#f87171'
                    },
                    warning: {
                        background: 'rgba(245,158,11,0.12)',
                        color: '#fbbf24'
                    },
                    secondary: {
                        background: '#1a1b1f',
                        color: text.secondary
                    }
                }
            }
        },
        menu: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#1a1b1f',
                        borderColor: '#26272c',
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    },
                    item: {
                        focusBackground: '#26272c',
                        color: text.primary,
                        focusColor: '#ffffff',
                        icon: {
                            color: text.secondary,
                            focusColor: text.primary
                        }
                    }
                }
            }
        },
        drawer: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#0b0c0e',
                        borderColor: '#26272c',
                        color: text.primary
                    }
                }
            }
        },
        inputtext: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#0b0c0e',
                        borderColor: '#34353b',
                        color: text.primary,
                        hoverBorderColor: '#4a4b52',
                        focusBorderColor: '#10b981',
                        shadow: 'none'
                    }
                }
            }
        },
        checkbox: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#0b0c0e',
                        borderColor: '#4a4b52',
                        hoverBorderColor: '#6b6b72',
                        checkedBackground: '#10b981',
                        checkedBorderColor: '#10b981',
                        checkedHoverBackground: '#34d399',
                        checkedHoverBorderColor: '#34d399'
                    },
                    icon: {
                        checkedColor: '#0b0c0e',
                        checkedHoverColor: '#0b0c0e'
                    }
                }
            }
        },
        message: {
            colorScheme: {
                dark: {
                    success: {
                        background: 'rgba(16,185,129,0.08)',
                        borderColor: 'rgba(16,185,129,0.3)',
                        color: '#34d399'
                    },
                    error: {
                        background: 'rgba(239,68,68,0.08)',
                        borderColor: 'rgba(239,68,68,0.3)',
                        color: '#f87171'
                    },
                    warn: {
                        background: 'rgba(245,158,11,0.08)',
                        borderColor: 'rgba(245,158,11,0.3)',
                        color: '#fbbf24'
                    }
                }
            }
        },
        select: {
            colorScheme: {
                dark: {
                    root: {
                        background: '#0b0c0e',
                        borderColor: '#34353b',
                        color: text.primary,
                        hoverBorderColor: '#4a4b52',
                        focusBorderColor: '#10b981',
                        shadow: 'none'
                    },
                    overlay: {
                        background: '#1a1b1f',
                        borderColor: '#26272c',
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    },
                    option: {
                        color: text.secondary,
                        focusBackground: '#26272c',
                        focusColor: text.primary,
                        selectedBackground: 'rgba(16,185,129,0.12)',
                        selectedColor: '#34d399',
                        selectedFocusBackground: 'rgba(16,185,129,0.18)',
                        selectedFocusColor: '#34d399'
                    }
                }
            }
        },
        paginator: {
            navButton: {
                borderRadius: '6px'
            },
            colorScheme: {
                dark: {
                    root: {
                        background: 'transparent'
                    },
                    navButton: {
                        background: 'transparent',
                        hoverBackground: '#1a1b1f',
                        selectedBackground: 'rgba(16,185,129,0.12)',
                        color: text.secondary,
                        hoverColor: text.primary,
                        selectedColor: '#34d399'
                    }
                }
            }
        },
        toast: {
            colorScheme: {
                dark: {
                    root: {
                        blur: '0'
                    },
                    success: {
                        background: '#131417',
                        borderColor: 'rgba(16,185,129,0.4)',
                        color: text.primary,
                        detailColor: text.secondary,
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    },
                    error: {
                        background: '#131417',
                        borderColor: 'rgba(239,68,68,0.5)',
                        color: text.primary,
                        detailColor: text.secondary,
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    },
                    warn: {
                        background: '#131417',
                        borderColor: 'rgba(245,158,11,0.5)',
                        color: text.primary,
                        detailColor: text.secondary,
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    },
                    info: {
                        background: '#131417',
                        borderColor: 'rgba(96,165,250,0.5)',
                        color: text.primary,
                        detailColor: text.secondary,
                        shadow: '0 4px 16px rgba(0,0,0,0.45)'
                    }
                }
            }
        }
    }
});

export default MyPreset;
